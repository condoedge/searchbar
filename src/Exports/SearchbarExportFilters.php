<?php

namespace Kompo\Searchbar\Exports;

use Kompo\Searchbar\SearchItems\Stores\ArraySearchStore;
use Kompo\Searchbar\SearchItems\Stores\SearchState;
use Kompo\Searchbar\SearchService;

/**
 * A table's filters (pills, default rules, search text) for an export with its own query and columns instead of the
 * table's rows (SearchbarTableExport): applyTo() narrows the export's query by them, lines() describes them. It holds
 * a snapshot of the state (strings and arrays): a queued export keeps the filters it was asked with, and its worker
 * never reads the live state.
 *
 * The table posts HasSearchbarFilters::searchbarExportParams() with the export, and its form values (the search box
 * text); the export, built in that request or in a later one carrying the same params (a modal asking the export
 * options), reads them with fromParams(). SISC: the members lists' TeamMembersExport.
 */
class SearchbarExportFilters
{
    // Only strings and arrays (no service kept): ExportPlugin walks a queued export's objects to drop its closures.
    public function __construct(protected string $serviceKey, protected array $snapshot)
    {
    }

    /**
     * The filters of the table state named by $params (searchbar_service, searchbar_store), with the search box text
     * when it came with them (searchbar_search: typed within the box's debounce, or not stored yet). Null when they
     * name no state of $entity (or a subclass): nothing posted, or another entity's state, whose filters mean nothing
     * on this query. The keys come from the client, as with every searchstate/* request: they only reach the user's
     * own states (session, own rows).
     */
    public static function fromParams(array $params, string $entity): ?static
    {
        $serviceKey = $params['searchbar_service'] ?? null;
        $storeKey = $params['searchbar_store'] ?? null;

        if (!is_string($serviceKey) || $serviceKey === '' || !is_string($storeKey) || $storeKey === '') {
            return null;
        }

        // Its own service: the request's singleton of that key may be bound to another state.
        $state = (new SearchService($serviceKey))->setStoreKey($storeKey)->getStore()->getState();
        $stateEntity = $state->getSearchableEntity();

        if (!is_string($stateEntity) || $stateEntity === '' || !is_a($stateEntity, $entity, true)) {
            return null;
        }

        if (array_key_exists('searchbar_search', $params)) {
            $state->setSearch(SearchService::capSearchText($params['searchbar_search']));
        }

        return new static($serviceKey, $state->toSnapshotArray());
    }

    /**
     * A search service on $snapshot (SearchState::toSnapshotArray()), held in memory: never the key's singleton, and
     * never the session, row or link the state came from.
     */
    public static function serviceFor(string $serviceKey, ?string $storeKey, array $snapshot): SearchService
    {
        $service = new SearchService($serviceKey);

        if (is_string($storeKey) && $storeKey !== '') {
            $service->setStoreKey($storeKey);
        }

        $store = new ArraySearchStore($service->getStoreKey());
        $service->setStore($store);
        $store->setFromCollection(collect($snapshot));

        return $service;
    }

    /**
     * $query narrowed by the filters (SearchService::getQuery()). As in a table: a query with its own ORDER BY requires
     * every full-text word, else any word matches and the best matches come first.
     */
    public function applyTo($query)
    {
        return $this->service()->getQuery($query);
    }

    public function state(): SearchState
    {
        return $this->service()->getStore()->getState();
    }

    public function getSnapshot(): array
    {
        return $this->snapshot;
    }

    /** The lines describing the filters (describe()), or none when the state is on its defaults. */
    public function lines(): array
    {
        $state = $this->state();

        return $state->isOnDefaults() ? [] : static::describe($state);
    }

    /** A title (entity, date and time), the search text, then each filter and default rule, as plain text. */
    public static function describe(SearchState $state): array
    {
        // A rule that can't describe itself (a host rule failing) is left out, not the export.
        $lines = $state->getRules()->map(fn($rule) => rescue(fn() => $rule->describe(), null, true))
            ->filter(fn($line) => is_string($line) && $line !== '')->values();

        if (($search = trim((string) $state->getSearch())) !== '') {
            $lines->prepend((string) __('filter.export-search-line', ['search' => $search]));
        }

        return $lines->prepend((string) __('filter.export-title', [
            'entity' => (string) $state->getSearchableInstance()?->searchableName(),
            'date' => now()->format('Y-m-d H:i'),
        ]))->all();
    }

    protected function service(): SearchService
    {
        return static::serviceFor($this->serviceKey, null, $this->snapshot);
    }
}
