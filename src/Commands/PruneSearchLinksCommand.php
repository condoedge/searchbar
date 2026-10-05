<?php

namespace Kompo\Searchbar\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kompo\Searchbar\Facades\SearchStateModel;
use Kompo\Searchbar\Models\SearchStateType;
use Kompo\Searchbar\SearchItems\Stores\DatabaseStore;
use Kompo\Searchbar\SearchItems\Stores\RecentSearches;
use Kompo\Searchbar\SearchItems\Stores\TableStore;

/**
 * Deletes, each kind by its own policy and on its own rows only (scheduled daily):
 * - "Open in a table" and shared-view links (LINK) not used for searchbar.link-lifetime-days (10; --days);
 * - the navbar's working states (WORKING rows but remembered tables: DatabaseStore) not changed for
 *   searchbar.working-lifetime-days (8): one per page display that changed its search;
 * - the remembered filters of tables (WORKING rows named tbl.*: TableStore) not used for searchbar.table-lifetime-days
 *   (180): never by the 8 days of the navbar's;
 * - each user's recent searches (RECENT) beyond searchbar.recent-searches (all of them when it is 0).
 */
class PruneSearchLinksCommand extends Command
{
    protected $signature = 'searchbar:prune-links {--days= : Override searchbar.link-lifetime-days (links only)}';

    // It prunes every kind of stored state now: the scheduled name is kept.
    protected $aliases = ['searchbar:prune'];

    protected $description = 'Delete the saved search links, the navbar working states and the remembered table filters not used for a number of days, and the recent searches beyond the kept number';

    public function handle()
    {
        $days = (int) ($this->option('days') ?? config('searchbar.link-lifetime-days', 10));
        $table = (new (SearchStateModel::getClass()))->getTable();

        // A plain delete: the model soft deletes, and these rows have no other use.
        $deleted = DB::table($table)
            ->where('type', SearchStateType::LINK->value)
            ->where('updated_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Deleted {$deleted} search link(s) older than {$days} day(s).");

        // --days is the links' lifetime: working states and remembered tables keep their own.
        $working = DatabaseStore::prune();

        $this->info("Deleted {$working} navbar working state(s) unchanged for " . DatabaseStore::lifetimeDays() . ' day(s).');

        $tables = TableStore::prune();

        $this->info("Deleted {$tables} remembered table state(s) unused for " . TableStore::lifetimeDays() . ' day(s).');

        // No age: the latest ones are kept (each search recorded already trims its user; this catches a lowered limit).
        $recents = RecentSearches::prune();

        $this->info("Deleted {$recents} recent search(es) beyond " . RecentSearches::limit() . ' per user.');

        return self::SUCCESS;
    }
}
