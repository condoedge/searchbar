<?php

namespace Kompo\Searchbar\SearchItems\Stores;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kompo\Searchbar\Facades\SearchStateModel;
use Kompo\Searchbar\Models\SearchStateType;

/**
 * The state of an "Open in a table" page, saved as a LINK search state and keyed by its token (?link=token):
 * short URLs that survive reloads and can be shared, instead of the whole state in the URL.
 *
 * Only its author writes a link: another user opening it works on a copy (SearchResults), so what was shared
 * never changes under them. Links not changed for searchbar.link-lifetime-days are deleted by searchbar:prune-links.
 * Its changes lock its row from the read to the write (mutate(), as DatabaseStore): the page's pill and filter actions
 * and a second tab on the same link don't revert each other.
 *
 * A table's shared view (HasSearchbarViews) is a LINK too, named 'table:' + the table's reference: a snapshot, written
 * once by snapshot() and never changed (not even by its author), touched each time it is opened.
 */
class LinkStore extends SearchStore
{
    const TABLE_PREFIX = 'table:';

    // Tries of one mutate() whose first write lost to another request's (see withLock()).
    const WRITE_TRIES = 3;

    protected ?SearchState $cachedState = null;
    // The link's raw state the cached state was read from or written as (see retrieveState()).
    protected ?string $cachedSource = null;
    protected bool $loaded = false;
    // The request the state was read for (as DatabaseStore: the store outlives it with its service).
    protected ?\WeakReference $loadedFor = null;
    // Inside mutate(): the link read FOR UPDATE (null: none yet), which the write checks and updates.
    protected bool $locking = false;
    protected $lockedLink = null;

    // Rules serialize their search service, which holds this store: without the cached state, or every stored rule
    // would carry a copy of the whole state.
    public function __sleep()
    {
        return $this->hasContext() ? ['key', 'searchContextService'] : ['key'];
    }

    // Read once per request: the state is read many times while rendering (SessionStore keeps it in memory too).
    protected function retrieveState(): ?SearchState
    {
        if (!$this->loaded || $this->loadedFor?->get() !== request()) {
            $this->loaded = true;
            $this->loadedFor = \WeakReference::create(request());

            $link = static::findLink($this->key, $this->locking);
            $this->lockedLink = $this->locking ? $link : null;
            $source = $link ? (string) $link->raw_state : null;

            // Unchanged since this store read or wrote it: that same object (as DatabaseStore::readRow()). Else rebuilt
            // through the entity's current filterables (StateCodec; a v1 link too). One that isn't a state any more
            // (its entity no longer a searchable) opens as a pruned link instead of failing on every reload.
            if (!$this->cachedState || $source === null || $this->cachedSource !== $source) {
                $this->cachedSource = $source;
                $this->cachedState = $source === null ? null
                    : rescue(fn() => StateCodec::decode($source, $this->getContext()), null, true);
            }
        }

        return $this->cachedState;
    }

    /**
     * mutate()'s lock, as DatabaseStore's: the change runs in a transaction on the link read FOR UPDATE; its author
     * and snapshot checks read that locked row. Two first writes of one token (a pruned link edited in two tabs): the
     * later one fails on the unique token and runs again on the link the other one wrote.
     */
    protected function withLock(callable $callback)
    {
        $db = (new (SearchStateModel::getClass()))->getConnection();
        $nested = $db->transactionLevel() > 0;

        try {
            return retry(static::WRITE_TRIES, fn() => $db->transaction(function () use ($callback) {
                $this->locking = true;

                try {
                    return $callback();
                } catch (\Throwable $e) {
                    // Rolled back: the state it changed isn't the link's (the next try reads it again).
                    $this->cachedState = $this->cachedSource = null;
                    $this->forgetLoaded();

                    throw $e;
                } finally {
                    $this->locking = false;
                    $this->lockedLink = null;
                }
            }), 25, fn($e) => DatabaseStore::isRetriable($e, $nested));
        } catch (\Throwable $e) {
            $this->cachedState = $this->cachedSource = null;
            $this->forgetLoaded();

            throw $e;
        }
    }

    // The next read goes to the link (the cached state stays, handed back while the link is unchanged).
    protected function forgetLoaded(): void
    {
        $this->loaded = false;
    }

    public function storeState($state): void
    {
        $this->cachedState = $state;
        $this->loaded = true;
        $this->loadedFor = \WeakReference::create(request());

        // Inside mutate(): the link read under the lock (a write never reads it again unlocked).
        $link = $this->locking ? $this->lockedLink : static::findLink($this->key);

        // A shared table view is a snapshot: whoever edits it (its author too) works in memory.
        if ($link && ((int) $link->user_id !== (int) auth()->id() || static::isSnapshot($link))) {
            return;
        }

        if (!$link) {
            // Only a token of a link's own format becomes one (the results page draws it: newToken()). Another user's
            // WORKING or RECENT token (64 hex, predictable from their id and a key) sent as a store key would take that
            // token's place in the unique index, and their table or recent search would fail to be written.
            if (!static::isToken($this->key)) {
                return;
            }

            $link = new (SearchStateModel::getClass());
            $link->name = 'link';
            $link->type = SearchStateType::LINK;
            $link->token = $this->key;
            $link->user_id = auth()->id();
        }

        // With pending pills and open editors: this is the page's working state, not a snapshot.
        $raw = StateCodec::toJson(StateCodec::encode($state));

        // Never read back over the cap (StateCodec): not written, the link keeps its last state (this request uses it).
        if (strlen($raw) > StateCodec::maxBytes()) {
            Log::warning('searchbar.state_too_large', ['key' => 'link', 'user_id' => auth()->id(), 'bytes' => strlen($raw)]);

            return;
        }

        $link->raw_state = $raw;

        static::withoutSecurity(fn() => $link->save());

        $this->cachedSource = $raw;

        if ($this->locking) {
            // Created in this mutate(): a nested write updates it.
            $this->lockedLink = $link;
        }
    }

    public function clearState(): void
    {
        $link = static::findLink($this->key);

        if ($link && (int) $link->user_id === (int) auth()->id()) {
            static::withoutSecurity(fn() => $link->forceDelete());
        }

        $this->cachedState = $this->cachedSource = null;
    }

    /**
     * The token is the capability: anyone it was shared with can open the link, whoever owns the record. $lock: read
     * FOR UPDATE (in a transaction: LinkStore::mutate()).
     */
    public static function findLink($token, bool $lock = false)
    {
        if (!static::isToken($token)) {
            return null;
        }

        try {
            $link = static::withoutSecurity(fn() => SearchStateModel::where('type', SearchStateType::LINK)->where('token', $token)->first());

            // Locked by its id once found (as DatabaseStore::lockedRow()): a locking read of a token without a row locks
            // the gap around it, where other first writes then wait or deadlock.
            return $link && $lock
                ? static::withoutSecurity(fn() => SearchStateModel::where('type', SearchStateType::LINK)->where('token', $token)
                    ->whereKey($link->getKey())->lockForUpdate()->first())
                : $link;
        } catch (\Illuminate\Database\QueryException $e) {
            // A locked read failing (a deadlock, a lock wait) is mutate()'s to handle: not an empty link.
            if ($lock) {
                throw $e;
            }

            // Deployed before its migration (no token column): the page opens empty instead of failing.
            report($e);

            return null;
        }
    }

    public static function newToken(): string
    {
        return Str::random(40);
    }

    /** A link's token as newToken() draws it: 40 letters or digits (never a WORKING or RECENT token: 64 hex). */
    public static function isToken($token): bool
    {
        return is_string($token) && preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1;
    }

    /** The name of the shared views of the table $tableRef (HasSearchbarViews). */
    public static function tableLinkName(string $tableRef): string
    {
        return static::TABLE_PREFIX . $tableRef;
    }

    /** A table's shared view: never rewritten (its author opening it at /search-results gets a copy too). */
    public static function isSnapshot($link): bool
    {
        return $link && str_starts_with((string) $link->name, static::TABLE_PREFIX);
    }

    /**
     * An immutable copy of $state named $name (a table's "Share this view"), owned by the signed-in user: its filters and
     * search text, never a pending pill, an open editor or the header sort (SearchState::toArray()). The same user
     * sharing the same state under the same name again gets the same token, not a new row per click. Null for a guest,
     * or a state over searchbar.max-state-kb (not written, logged).
     */
    public static function snapshot(SearchState $state, string $name): ?string
    {
        $userId = auth()->id();

        if (!$userId) {
            return null;
        }

        $raw = StateCodec::toJson($state->toArray());

        if (strlen($raw) > StateCodec::maxBytes()) {
            Log::warning('searchbar.state_too_large', ['key' => $name, 'user_id' => $userId, 'bytes' => strlen($raw)]);

            return null;
        }

        // Compared here, byte for byte: the column's collation ignores case and accents ("Marie" would reuse "marie").
        $existing = static::withoutSecurity(fn() => SearchStateModel::where('type', SearchStateType::LINK)
            ->where('user_id', $userId)->where('name', $name)->latest('id')->limit(50)->get())
            ->first(fn($link) => (string) $link->raw_state === $raw);

        if ($existing) {
            // Shared again: kept as long as a new one would be.
            static::touchLink($existing);

            return $existing->token;
        }

        $link = new (SearchStateModel::getClass());
        $link->name = mb_substr($name, 0, 255);
        $link->type = SearchStateType::LINK;
        $link->token = static::newToken();
        $link->user_id = $userId;
        $link->raw_state = $raw;

        static::withoutSecurity(fn() => $link->save());

        return $link->token;
    }

    /**
     * The link was opened (a table's shared view, a results page): searchbar:prune-links deletes the links not changed
     * for link-lifetime-days, and a snapshot never changes, so opening counts as a change (a link still used isn't
     * pruned). No model event.
     */
    public static function touchLink($link): void
    {
        DB::table($link->getTable())->where('id', $link->getKey())->update(['updated_at' => $link->freshTimestampString()]);
    }

    protected static function withoutSecurity(callable $callback)
    {
        return function_exists('executeInBypassContext') ? executeInBypassContext($callback) : $callback();
    }
}
