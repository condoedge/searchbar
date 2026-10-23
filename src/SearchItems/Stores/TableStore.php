<?php

namespace Kompo\Searchbar\SearchItems\Stores;

use Kompo\Searchbar\Models\SearchStateType;

/**
 * The remembered filters of a table (HasSearchbarFilters), per user and table: a table reopens with its last pills and
 * search text. Its store key is 'tbl.' + the table's reference (searchbarRememberKey()); SearchService::getStore()
 * routes such keys here whatever the service, since searchstate/* requests carry only the service and store keys.
 *
 * The row is named by that key, so the rows of remembered tables are told apart from other working states: they are
 * kept searchbar.table-lifetime-days (180) after their last use (prune(); the navbar's 8 days never apply to them), and
 * each user keeps at most searchbar.max-remembered-tables (200) of them: creating one more deletes the user's least
 * recently used. Rows are created by a table's own display only (a searchstate/* request never creates one, see
 * SearchStateController). A guest's table isn't remembered: its state lives for the request (never in the session).
 */
class TableStore extends DatabaseStore
{
    const PREFIX = 'tbl.';

    /** Remembered tables a user keeps (searchbar.max-remembered-tables): a table has one per user (and context). */
    public static function maxRowsPerUser(): int
    {
        return max(1, (int) config('searchbar.max-remembered-tables', 200));
    }

    /**
     * Deletes the user's least recently used remembered tables beyond maxRowsPerUser() (they open on their defaults
     * again). The row just written is the most recent: never one of them.
     */
    protected static function trimUserRows($userId): void
    {
        static::trimRows(static::tableRows()->where('user_id', $userId), static::maxRowsPerUser());
    }

    // Never kept in the session: a guest's table is in memory (DatabaseStore), and no table state was ever stored in
    // the session under a tbl. key.
    protected function guestStore(): ?SessionStore
    {
        return null;
    }

    protected function sessionState(): ?SearchState
    {
        return null;
    }

    // Its key as it is (tbl.…, checked by handles()): the name the tables' prune and cap go by.
    protected function rowName(): string
    {
        return (string) $this->key;
    }

    public static function handles($key): bool
    {
        return is_string($key) && preg_match('/^tbl\.[A-Za-z0-9._-]{1,150}$/', $key) === 1;
    }

    /**
     * The store key of a table reference. A reference with other characters than letters, digits, '.', '_' and '-', or
     * longer than 150, is cleaned and gets a hash of the original (two references never share a key).
     */
    public static function keyFor(string $ref): string
    {
        $clean = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $ref), '-');

        if ($clean !== $ref || strlen($clean) > 150 || $clean === '') {
            $clean = ltrim(substr($clean, 0, 100) . '-', '-') . substr(md5($ref), 0, 16);
        }

        return static::PREFIX . $clean;
    }

    /** Days a remembered table is kept after its last use (shown or changed). */
    public static function lifetimeDays(): int
    {
        return max(1, (int) config('searchbar.table-lifetime-days', 180));
    }

    /**
     * Deletes the remembered tables not used for $days (default: lifetimeDays()). Returns how many. Run it daily (the
     * searchbar:prune-links schedule).
     */
    public static function prune(?int $days = null): int
    {
        return static::tableRows()
            ->where('updated_at', '<', now()->subDays($days ?? static::lifetimeDays()))
            ->delete();
    }

    /** The working states of remembered tables (named by their tbl. key). */
    protected static function tableRows()
    {
        return static::db()->table(static::table())
            ->where('type', SearchStateType::WORKING->value)
            ->where('name', 'like', static::PREFIX . '%');
    }
}
