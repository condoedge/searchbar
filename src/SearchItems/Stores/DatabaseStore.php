<?php

namespace Kompo\Searchbar\SearchItems\Stores;

use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Kompo\Searchbar\Facades\SearchStateModel;
use Kompo\Searchbar\Models\SearchStateType;

/**
 * A working state (pending pills and open editors included) kept in a search_states row of type WORKING, private to
 * its user: the row is found by a token hashing the user id with the store key, so a store key sent by a client only
 * ever reaches that user's own row. One row per user and key (the unique token). Read once per request.
 *
 * The navbar's store for signed-in users when searchbar.store names it: one row per page display whose search was
 * changed (the navbar draws its store key on each display), deleted searchbar.working-lifetime-days (8) after its last
 * change (searchbar:prune-links), at most searchbar.max-working-states per user. Guests keep the session
 * (SessionStore). Remembered tables are rows of it too (TableStore: their own lifetime and cap).
 *
 * Why rows, not the session: Laravel writes the whole session back at the end of every request, so a request that
 * overlapped a change (the results' lazy load, an option search, the entity counts, another tab) put back the state
 * it had read. A row is written by changes only, each a read-modify-write under the row's lock (mutate(): SELECT ...
 * FOR UPDATE in a transaction). A row lock rather than Cache::lock(): it holds wherever the rows are (several web
 * nodes), whatever the cache driver (file locks are per server, and cache:clear would wipe states kept in a cache), so
 * production's SESSION_DRIVER, CACHE_DRIVER and number of web nodes (still to confirm) don't matter to it.
 *
 * The query builder, not the model: no kompo-auth security or model events on each read and write, and the user
 * filter is explicit. On the search state model's connection.
 */
class DatabaseStore extends SearchStore
{
    // Tries of one mutate() whose first write lost to another request's (see withLock()).
    const WRITE_TRIES = 3;
    // The source of an empty state: no row.
    const NO_ROW = "\0";

    protected ?SearchState $cachedState = null;
    // The row's raw state the cached state was read from or written as (NO_ROW: none), see readRow().
    protected ?string $cachedSource = null;
    protected bool $loaded = false;
    // The request the state was read for: a store outlives its request with its service (a singleton), in Kompo's
    // refresh-many (its sub-requests), tests and long-lived workers. Read again for another one.
    protected ?\WeakReference $loadedFor = null;
    // The row read or written in this request: its id (mutate() writes to it), updated_at (markUsed()), raw state.
    protected $rowId = null;
    protected $rowUpdatedAt = null;
    protected ?string $rowRaw = null;
    // Inside mutate(): the row is read FOR UPDATE and written by its id.
    protected bool $locking = false;
    // mutate() inserted the row (trimUserRows() once committed).
    protected bool $created = false;
    // This store wrote its row: a store key drawn in this request (isFreshKey()) may have a row then.
    protected bool $wrote = false;
    // The state came from the session (see sessionState()): forgotten there once written here.
    protected bool $fromSession = false;
    protected ?SessionStore $guestStore = null;

    // As LinkStore: rules serialize their search service, which holds this store; without the cached state, or each
    // stored rule would carry a copy of the whole state.
    public function __sleep()
    {
        return $this->hasContext() ? ['key', 'searchContextService'] : ['key'];
    }

    /** The row's state, else an empty one, read once per request (readRow()). */
    protected function retrieveState(): ?SearchState
    {
        if ($guest = $this->guestStore()) {
            return $guest->getState();
        }

        if (!$this->loaded || $this->loadedFor?->get() !== request()) {
            $this->loaded = true;
            $this->loadedFor = \WeakReference::create(request());
            $this->cachedState = $this->readRow();
        }

        return $this->cachedState;
    }

    /**
     * The state stored now. Unchanged since this store read or wrote it, that same object: the session store hands
     * back its state while the session holds the same data, and the komponents of a request hold it (a change made
     * through mutate() is theirs too; changes made to it before a write are seen by the next read). Else rebuilt from
     * the row, or an empty state when there is none.
     */
    protected function readRow(): SearchState
    {
        $this->rowId = $this->rowUpdatedAt = $this->rowRaw = null;
        $userId = $this->userId();
        $row = null;

        // A key drawn by this request (a page display) has no row, unless this store wrote it: no query.
        if ($userId && ($this->wrote || !$this->isFreshKey())) {
            $row = $this->locking
                ? $this->lockedRow($userId)
                : $this->rows($userId)->first(['id', 'raw_state', 'updated_at', 'deleted_at']);
        }

        $this->rowId = $row?->id;

        if ($row && $row->deleted_at === null) {
            $this->rowUpdatedAt = $row->updated_at;
            $this->rowRaw = (string) $row->raw_state;
        }

        $source = $this->rowRaw ?? static::NO_ROW;

        if ($this->cachedState && $this->cachedSource === $source) {
            return $this->cachedState;
        }

        $this->cachedSource = $source;
        $this->fromSession = false;

        if ($this->rowRaw === null) {
            return ($userId && !$row ? $this->sessionState() : null) ?? $this->getBaseState();
        }

        // Rebuilt through the entity's current filterables (StateCodec; a v1 row too): a rule that no longer fits is
        // dropped. A row that isn't a state any more (its entity no longer a searchable) opens on the defaults and the
        // next write replaces it, instead of failing on every display.
        $state = rescue(fn() => StateCodec::decode($this->rowRaw, $this->getContext()), null, true);

        if ($state && static::rewritesDropped($state) && !$this->locking) {
            $this->storeWithoutDroppedRules($state);
        }

        return $state ?? $this->getBaseState();
    }

    /**
     * mutate()'s read: the row locked FOR UPDATE until the transaction ends, a soft deleted one too (its token is
     * taken, the write brings it back). Found first without a lock, then locked by its id: a locking read of a token
     * that has no row locks the gap around it in the unique index (REPEATABLE READ), where the first writes of other
     * keys then wait or deadlock. A first write racing another one fails on the unique token instead (withLock()).
     */
    protected function lockedRow($userId)
    {
        $id = $this->rows($userId, true)->value('id');

        return $id === null ? null : static::db()->table(static::table())->where('id', $id)
            ->where('token', $this->token($userId))->lockForUpdate()->first(['id', 'raw_state', 'updated_at', 'deleted_at']);
    }

    public function storeState($state): void
    {
        if ($guest = $this->guestStore()) {
            $guest->storeState($state);

            return;
        }

        $this->cachedState = $state;
        $this->loaded = true;
        $this->loadedFor = \WeakReference::create(request());

        $userId = $this->userId();

        // A guest's remembered table (TableStore): kept for the request only.
        if (!$userId) {
            $this->cachedSource = static::NO_ROW;

            return;
        }

        // With pending pills and open editors: the working state (a reopened table cleans it, see bootSearchbar()).
        $raw = StateCodec::toJson(StateCodec::encode($state));

        // A row lives for days or months and its size is the client's doing (pills, their values): one over the cap
        // isn't written. This request still uses the state; the row keeps its last one.
        if (strlen($raw) > static::maxStateBytes()) {
            Log::warning('searchbar.state_too_large', ['key' => $this->key, 'user_id' => $userId, 'bytes' => strlen($raw)]);

            return;
        }

        $now = now();
        $values = ['raw_state' => $raw, 'modified_by' => $userId, 'updated_at' => $now, 'deleted_at' => null];
        $row = [
            'name' => $this->rowName(),
            'token' => $this->token($userId),
            'type' => SearchStateType::WORKING->value,
            'user_id' => $userId,
            'added_by' => $userId,
            'created_at' => $now,
        ] + $values;

        if ($this->locking && $this->rowId) {
            // mutate(): the row read under the lock.
            static::db()->table(static::table())->where('id', $this->rowId)->update($values);
        } elseif ($this->locking) {
            // mutate() found no row: a first write. Another request's first write of this key, committed meanwhile,
            // makes this insert fail on the unique token (or wait for it, then fail): withLock() then runs the change
            // again, on that row.
            $this->rowId = static::db()->table(static::table())->insertGetId($row);
            $this->created = true;
        } else {
            // A whole state written without reading it first: one statement, no duplicate row. A soft deleted row of
            // this token comes back (deleted_at cleared). A row not read in this request may be a new one: trimmed.
            $creating = $this->rowUpdatedAt === null;

            static::db()->table(static::table())->upsert([$row], ['token'], array_keys($values));

            $this->rowId = null;
            $creating && static::trimUserRows($userId);
        }

        $this->rowUpdatedAt = $now;
        $this->rowRaw = $this->cachedSource = $raw;
        $this->wrote = true;

        if ($this->fromSession) {
            // Moved here: the session's copy would come back if this row went.
            (new SessionStore($this->key))->clearState();
            $this->fromSession = false;
        }
    }

    public function clearState(): void
    {
        if ($guest = $this->guestStore()) {
            $guest->clearState();

            return;
        }

        if ($userId = $this->userId()) {
            $this->rows($userId, true)->delete();
        }

        $this->cachedState = $this->cachedSource = null;
        $this->forgetLoaded();
    }

    /**
     * mutate()'s lock: the change runs in a transaction on the row read FOR UPDATE (lockedRow()), written by its id.
     * It runs again (isRetriable()) when two first writes of one key race (no row to lock yet: the later one fails on
     * the unique token), on a deadlock, and when the locking read finds the row changed since the transaction's snapshot
     * (MariaDB's innodb_snapshot_isolation, on by default from 11.6.2: the other request committed while this one
     * waited for the lock).
     */
    protected function withLock(callable $callback)
    {
        if (!$this->userId()) {
            // A guest: the session (or in memory), nothing to lock.
            return $callback();
        }

        $db = static::db();
        $nested = $db->transactionLevel() > 0;
        $this->created = false;

        try {
            $result = retry(static::WRITE_TRIES, fn() => $db->transaction(function () use ($callback) {
                $this->locking = true;
                $this->created = false;

                try {
                    return $callback();
                } catch (\Throwable $e) {
                    // Rolled back: the state it changed isn't the row's (the next try reads the row again).
                    $this->cachedState = $this->cachedSource = null;
                    $this->forgetLoaded();

                    throw $e;
                } finally {
                    $this->locking = false;
                }
            }), 25, fn($e) => static::isRetriable($e, $nested));
        } catch (\Throwable $e) {
            $this->cachedState = $this->cachedSource = null;
            $this->forgetLoaded();

            throw $e;
        }

        if ($this->created) {
            // Once committed: the user's other rows aren't locked while this one is written.
            $this->created = false;
            static::trimUserRows($this->userId());
        }

        return $result;
    }

    /**
     * A failed try of mutate() worth running again, in a transaction of its own (LinkStore's too): 1062 (a first write
     * lost the unique token to another one), 1213 (deadlock), 1020 (record changed since the snapshot). Never inside a
     * transaction opened by the caller: its snapshot still doesn't see the other request's row (a 1062 fails the
     * same way again), and a deadlock rolled that whole transaction back.
     */
    public static function isRetriable(\Throwable $e, bool $nested): bool
    {
        if ($nested) {
            return false;
        }

        $code = $e instanceof QueryException ? (int) ($e->errorInfo[1] ?? 0) : 0;

        return in_array($code, [1062, 1213, 1020], true) || $e instanceof DeadlockException;
    }

    /**
     * A state read with rules that no longer fit is written once without them. Not when a rule failed to rebuild
     * (StateCodec::failedDrops(): a filterable throwing now, no searchable to rebuild with): its stored data may be
     * fine, and writing the state would lose it for good. SessionStore and a table's reopen follow this too.
     */
    public static function rewritesDropped(SearchState $state): bool
    {
        return $state->droppedStoredRules() && !StateCodec::failedDrops($state->droppedStoredRules());
    }

    /**
     * The row's name: its store key. Prunes and caps go by it (remembered tables are the rows named tbl.…: TableStore,
     * whose keys pass TableStore::handles()). A key of this store that only looks like a table's (TBL.x, tbl.a b, one
     * too long: sent by a client) is named apart, so it gets the navbar's prune and cap, not a table's.
     */
    protected function rowName(): string
    {
        $name = (string) $this->key;

        return mb_substr(stripos($name, TableStore::PREFIX) === 0 ? '~' . $name : $name, 0, 255);
    }

    // The next read goes to the row (the cached state stays, handed back while the row is unchanged: readRow()).
    protected function forgetLoaded(): void
    {
        $this->loaded = false;
        $this->rowId = $this->rowUpdatedAt = $this->rowRaw = null;
    }

    /**
     * The row was used (shown again) without being changed: its age counts from now (unused rows are pruned). At most
     * one write a day.
     */
    public function markUsed(): void
    {
        $this->retrieveState();
        $userId = $this->userId();

        if (!$userId || !$this->rowUpdatedAt || Carbon::parse($this->rowUpdatedAt)->gt(now()->subDay())) {
            return;
        }

        $this->rows($userId)->update(['updated_at' => $now = now()]);
        $this->rowUpdatedAt = $now;
    }

    /**
     * This user has a row of this key (read, or written in this request). A row that no longer decodes counts:
     * it is there, and its next write replaces it.
     */
    public function hasRow(): bool
    {
        $this->retrieveState();

        return $this->rowUpdatedAt !== null;
    }

    /** Largest stored state written (searchbar.max-state-kb, 256): a few pills take a few hundred bytes (v2 JSON). */
    public static function maxStateBytes(): int
    {
        return StateCodec::maxBytes();
    }

    /**
     * A row read with rules that no longer fit (dropped and logged: StateCodec) is written once without them, instead
     * of dropping and logging them on every read (the navbar is on every page). Only while it still holds what was
     * read (compared under its lock, byte for byte: the column's collation ignores case): a change written meanwhile
     * stays. Its age doesn't change.
     */
    protected function storeWithoutDroppedRules(SearchState $state): void
    {
        [$id, $read] = [$this->rowId, $this->rowRaw];
        $raw = StateCodec::toJson(StateCodec::encode($state));

        $written = rescue(fn() => static::db()->transaction(function () use ($id, $read, $raw) {
            $rows = fn() => static::db()->table(static::table())->where('id', $id);

            if ($rows()->lockForUpdate()->value('raw_state') !== $read) {
                return false;
            }

            $rows()->update(['raw_state' => $raw]);

            return true;
        }), false, true);

        if ($written) {
            $state->setDroppedStoredRules([]);
            $this->rowRaw = $this->cachedSource = $raw;
        }
    }

    /**
     * No row: the state the session holds under this key, if any. A page displayed before the navbar's states were
     * rows (the deploy switching searchbar.store) keeps its pills: the next write moves the state here.
     */
    protected function sessionState(): ?SearchState
    {
        $state = $this->hasContext() ? (new SessionStore($this->key))->injectContext($this->getContext())->retrieveState() : null;

        $this->fromSession = $state !== null;

        return $state;
    }

    /** A guest's state is in the session, as before the navbar's rows (TableStore: none, see there). */
    protected function guestStore(): ?SessionStore
    {
        if ($this->userId() || !$this->hasContext()) {
            return null;
        }

        return $this->guestStore ??= (new SessionStore($this->key))->injectContext($this->getContext());
    }

    /** The store key was drawn in this request (SearchService::isFreshStoreKey()). */
    protected function isFreshKey(): bool
    {
        return $this->hasContext() && $this->getContext()->getStoreKey() === $this->key && $this->getContext()->isFreshStoreKey();
    }

    /** Working states a user keeps besides remembered tables (searchbar.max-working-states): one per page display. */
    public static function maxRowsPerUser(): int
    {
        return max(1, (int) config('searchbar.max-working-states', 100));
    }

    /**
     * Deletes the user's least recently changed working states beyond maxRowsPerUser(): a page display that old has
     * gone (its navbar opens empty if it comes back). The row just written is the most recent: never one of them.
     * Store keys come from the client: this bounds the rows a user can make.
     */
    protected static function trimUserRows($userId): void
    {
        static::trimRows(static::workingRows()->where('user_id', $userId), static::maxRowsPerUser());
    }

    /** Deletes the least recently used of $rows beyond $max. */
    protected static function trimRows($rows, int $max): void
    {
        $excess = (clone $rows)->count() - $max;

        if ($excess > 0) {
            static::db()->table(static::table())->whereIn('id', (clone $rows)->orderBy('updated_at')->orderBy('id')->limit($excess)->pluck('id'))->delete();
        }
    }

    /** Days a navbar's working state is kept after its last change (searchbar.working-lifetime-days). */
    public static function lifetimeDays(): int
    {
        return max(1, (int) config('searchbar.working-lifetime-days', 8));
    }

    /**
     * Deletes the working states not changed for $days (default: lifetimeDays()), never a remembered table (TableStore
     * has its own lifetime). Returns how many. Run daily (the searchbar:prune-links schedule).
     */
    public static function prune(?int $days = null): int
    {
        return static::workingRows()
            ->where('updated_at', '<', now()->subDays($days ?? static::lifetimeDays()))
            ->delete();
    }

    /** Working states but remembered tables (TableStore rows are named by their tbl. key). */
    protected static function workingRows()
    {
        return static::db()->table(static::table())
            ->where('type', SearchStateType::WORKING->value)
            ->where('name', 'not like', TableStore::PREFIX . '%');
    }

    /** The row of this user and key: its token hashes both, and the user is checked too. */
    protected function rows($userId, bool $withDeleted = false)
    {
        return static::db()->table(static::table())
            ->where('type', SearchStateType::WORKING->value)
            ->where('token', $this->token($userId))
            ->where('user_id', $userId)
            ->when(!$withDeleted, fn($query) => $query->whereNull('deleted_at'));
    }

    /** 64 hex characters (the token column; a LINK token has 40): the user id is part of it, so users never share a row. */
    protected function token($userId): string
    {
        return hash('sha256', 'working|' . $userId . '|' . $this->key);
    }

    protected function userId()
    {
        return auth()->id();
    }

    protected static function model()
    {
        return new (SearchStateModel::getClass());
    }

    protected static function table(): string
    {
        return static::model()->getTable();
    }

    protected static function db(): \Illuminate\Database\ConnectionInterface
    {
        return static::model()->getConnection();
    }
}
