<?php

namespace Kompo\Searchbar\Components\Concerns;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kompo\Searchbar\SearchItems\Stores\DatabaseStore;
use Kompo\Searchbar\SearchItems\Stores\TableStore;

/**
 * Remembered filters of a table (part of HasSearchbarFilters): the table reopens with the user's last pills and search
 * text, kept in a TableStore row per user and table, and shows a "Reset filters" link while they differ from the
 * table's defaults. On by default (searchbar.remember-tables); a table opts out with searchbarRememberKey() returning
 * null. Defines no created(): bootSearchbar() calls it.
 *
 * A reopened state is checked by the table's first searchbarQuery() of that request (searchbarCheckedQuery()): a
 * remembered filter that no longer runs puts the table back on its defaults instead of breaking every visit.
 */
trait RemembersSearchbarState
{
    // Set by searchbarReopen(): the next searchbarQuery() checks the rows' query before the table uses it.
    protected bool $searchbarCheckReopenedQuery = false;

    /** Per table class: unique (a hash of the class name) and safe in a store key, anonymous classes included. */
    protected function searchbarTableRef(): string
    {
        return Str::limit(Str::slug(Str::kebab(class_basename(static::class))), 60, '') . '-' . substr(md5(static::class), 0, 8);
    }

    /**
     * The key under which this table remembers its filters for each user, or null to forget them on reload (a state
     * per page view, as before). Override it to opt out (return null); to add context (a team), override
     * searchbarRememberContext(). Null with searchbar.remember-tables off and for a service with its own store
     * (searchbar.service-stores: the results page's links). Guests are never remembered, whatever it returns.
     */
    protected function searchbarRememberKey(): ?string
    {
        $entity = property_exists($this, 'searchableEntity') ? $this->searchableEntity : null;
        $context = (string) $this->searchbarRememberContext();

        return $entity && auth()->check() && config('searchbar.remember-tables', true)
            && !isset(config('searchbar.service-stores', [])[$this->getServiceKey()])
            ? $this->searchbarTableRef() . ($context !== '' ? '-' . $context : '')
            : null;
    }

    /**
     * What the table remembers apart within its class, e.g. the team a list shows (its filters name that team's
     * sub-teams and roles). Null: one state per table class. Read in created(), after the host set its properties.
     */
    protected function searchbarRememberContext(): ?string
    {
        return null;
    }

    /** The store key of this table's remembered state (tbl.…), or null when it doesn't remember. */
    protected function searchbarRememberedStoreKey(): ?string
    {
        // A guest's state would live for one request only (no row): each browse would reopen an empty one.
        $key = auth()->check() ? $this->searchbarRememberKey() : null;

        return is_string($key) && $key !== '' ? TableStore::keyFor($key) : null;
    }

    /** The table's state is its user's remembered row (TableStore). */
    public function searchbarRemembers(): bool
    {
        return TableStore::handles($this->storeKey);
    }

    /**
     * The state key of a table being opened: the page display, or a table rebuilt by its parent (no store key yet; the
     * Kompo requests of a displayed table carry it in their encrypted boot info). Its remembered key, else none
     * (setSearchProps() then draws a session key, as before). Also when the props hold another remembered key than this
     * table's own (the page URL's query string fills them): a link can't make a table use or reset another table's
     * filters. Returns whether the table is being opened.
     */
    protected function searchbarPickStoreKey(): bool
    {
        $given = $this->prop('storeKey');
        $own = $this->searchbarRememberedStoreKey();

        if (is_string($given) && $given !== '' && !(TableStore::handles($given) && $given !== $own)) {
            return false;
        }

        $this->store(['storeKey' => $own]);

        return true;
    }

    /**
     * A state shown again (its pills may have been left pending or open in an editor, its premade rules built for
     * another team, a filter no longer declared): cleaned for the viewer's current context, and stored only when that
     * changed it. Its query is checked by the next searchbarQuery(), which marks the row used (kept from pruning) once
     * it runs: a broken row must not be kept alive by the visits it breaks.
     */
    protected function searchbarReopen(): void
    {
        if (!$this->state->getSearchableEntity()) {
            return;
        }

        // Stored as cleaned: the state read (the same object while unchanged since), else the stored one cleaned in turn
        // under its lock (another tab changed it meanwhile).
        if ($this->state->cleanForReopen($this->state->getSearchableInstance())) {
            $this->state = $this->searchService->getStore()->mutate(fn($state) => $state === $this->state
                || ($state->getSearchableEntity() && $state->cleanForReopen($state->getSearchableInstance())));
            $this->searchableInstance = $this->state->getSearchableInstanceForResultsPanel();
        }

        $this->searchbarCheckReopenedQuery = true;
    }

    /**
     * The rows' query of a reopened table, checked before the table uses it. A remembered filter may no longer run (a
     * column renamed or dropped by a later deploy, a scope removed, a filter broken since): its error on every
     * display locked the user out of the table, since the reset link and the custom filters modal are drawn by that
     * same display. The query is built and run with LIMIT 0 (checked by the database, no row read). If that fails,
     * the state goes back to the table's defaults with no search text, is stored, and the error is reported; unless
     * the defaults fail too: then the stored filters aren't the cause (the host query, the database), they are kept
     * and the error is thrown as before.
     */
    protected function searchbarCheckedQuery($baseQuery)
    {
        $this->searchbarCheckReopenedQuery = false;

        // Kept aside: a build that failed halfway may have added some of the stored rules to $baseQuery.
        $pristine = is_object($baseQuery) ? clone $baseQuery : $baseQuery;

        try {
            $query = $this->searchbarTriedQuery($baseQuery);
        } catch (\Throwable $error) {
            $query = $this->searchbarDefaultsQuery($pristine, $error);
        }

        $store = $this->searchService->getStore();

        if ($store instanceof DatabaseStore) {
            $store->markUsed();
        }

        return $query;
    }

    /** The state's query, run once with LIMIT 0: an unknown column, table or scope throws here. */
    protected function searchbarTriedQuery($baseQuery)
    {
        $query = $this->searchService->getQuery($baseQuery);

        if (is_object($query)) {
            // On a clone: the table's query keeps its own limit (an Eloquent builder or relation clones its base query).
            $probe = clone $query;
            (method_exists($probe, 'toBase') ? $probe->toBase() : $probe)->limit(0)->get();
        }

        return $query;
    }

    /** After $error: the defaults' query, the state reset and stored. If the defaults fail too: state kept, $error thrown. */
    protected function searchbarDefaultsQuery($baseQuery, \Throwable $error)
    {
        [$rules, $search] = [$this->state->getRules(), $this->state->getSearch()];

        $this->state->setRules($this->state->getSearchableInstance()->getDefaultRulesApplied()->values())->setSearch(null);

        try {
            $query = $this->searchbarTriedQuery($baseQuery);
        } catch (\Throwable) {
            $this->state->setRules($rules)->setSearch($search);

            throw $error;
        }

        report($error);
        Log::warning('searchbar.remembered_filters_reset', ['table' => static::class, 'storeKey' => $this->storeKey]);
        $this->state = $this->searchService->getStore()->mutate(fn($state) => $state->getSearchableInstance()
            && $state->setRules($state->getSearchableInstance()->getDefaultRulesApplied()->values())->setSearch(null));

        return $query;
    }

    /** No search text, no filter pill, and exactly the entity's default premade rules: nothing to reset. */
    protected function searchbarIsDefaultState(): bool
    {
        return $this->state->isOnDefaults();
    }

    /**
     * After the pills of a remembered table that isn't on its defaults: remembered filters come back on every visit,
     * so the way back to the defaults is shown, not only in the custom filters modal.
     */
    protected function searchbarResetLink()
    {
        if (!$this->searchbarRemembers() || $this->searchbarIsDefaultState()) {
            return null;
        }

        return $this->searchbarAction(
            _Link('filter.reset-filter')->class('text-xs text-gray-500 hover:underline whitespace-nowrap searchbar-reset-filters')
                ->title('filter.reset-filter-hint'),
            'searchstate.reset-rules',
        );
    }
}
