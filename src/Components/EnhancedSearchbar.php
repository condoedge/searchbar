<?php

namespace Kompo\Searchbar\Components;

use Condoedge\Utils\Kompo\Common\Query;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Kompo\Searchbar\SearchItems\Stores\RecentSearches;

class EnhancedSearchbar extends Query
{
    use SearchKomponentUtils;

    public $id = SearchbarIds::RESULTS;

    // Mounted results (searchbarClientJs(): a change while they were still lazy-loading reloads them).
    public $class = 'searchbar-results';

    public $perPage = 6;
    public $paginationType = 'Scroll';
    public $style = 'width: calc(100% - 5px)';
    // Scroll pagination listens on the items wrapper, so it must be the element that scrolls:
    // capped below one page of cards (6 × ~100px), or page 2 is never requested.
    public $itemsWrapperClass = 'overflow-y-auto mini-scroll';
    public $itemsWrapperStyle = 'max-height: 450px';

    public function noItemsFound()
    {
        // A search too short to run (query() lists nothing): say what's missing, not "no results".
        if ($this->searchTooShort()) {
            return _Rows(
                _Sax('search-status', 32)->class('text-greenmain opacity-60 mb-2'),
                // Worded per word ("a word of at least :min letters or digits"): "a b" or "j-p" is too short.
                _Html(__('navbar.search.type-min-chars', ['min' => $this->searchService::minSearchChars()]))->class('text-sm text-gray-500'),
            )->class('items-center text-center bg-level5 bg-opacity-40 rounded-2xl p-6 m-2');
        }

        // card-level5 doesn't exist: the empty state was unstyled.
        return _Rows(
            _Sax('search-status', 32)->class('text-greenmain opacity-60 mb-2'),
            _Html('navbar.no-results')->class('font-semibold text-greenmain'),
            _Html('navbar.no-results-hint')->class('text-sm text-gray-500'),
        )->class('items-center text-center bg-level5 bg-opacity-40 rounded-2xl p-6 m-2');
    }

    /**
     * Kompo renders the "OnLoad" filters after the cards, when the query is the paginator: its total already counts
     * every match (the full count of "Open in a table", never "100+"). top() counted them a second time, on each
     * refresh and each scrolled page (a browse request boots top() too): on the people, 45 ms for "martin", 240 ms for
     * "mar", 310 ms for the empty panel.
     */
    public function topOnLoad()
    {
        $count = $this->query instanceof LengthAwarePaginator ? $this->query->total() : 0;
        $searchableEntity = $this->state->getSearchableInstanceForResultsPanel();
        $spinnerId = 'search-panel-loading' . $this->getServiceKey();

        return _Rows(
            // Turn the navbar search loading state off once the (re)fetched results
            // have mounted. The navbar's hidden #navbar-search-send link (searchstate.set-search)
            // refreshes only this komponent and searchable-options, so the off-switch must
            // live here rather than in SearchPanel. Given the search these
            // results are for, it keeps the lock while newer typed text is still on its way.
            // Then, results computed before a change made while they were lazy-loading reload once
            // (searchbarResultsLoaded, through the link below). An outlined result that was replaced is let go: these
            // are other results (searchbarKbAfterRefresh).
            _Hidden()->onLoad(fn($e) => $e->run('() => { window.searchbarLoadingOff ? searchbarLoadingOff("' . $spinnerId . '", '
                . json_encode(trim((string) $this->state->getSearch()), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT)
                . ') : (window.searchLoadingOff && searchLoadingOff("' . $spinnerId . '")); window.searchbarResultsLoaded && searchbarResultsLoaded(); window.searchbarKbAfterRefresh && searchbarKbAfterRefresh(); }')),
            _Link()->class('hidden searchbar-results-reload')->refresh(),

            // The user's recent searches while nothing is typed or filtered (typing hides them at once:
            // searchbarClientJs()), above the entity and its cards: the column's first keyboard items.
            $this->showsRecentSearches() ? $this->instanciateSearchKomponent(RecentSearchesList::class) : null,

            !$searchableEntity ? null : _Html($searchableEntity::searchableName())
                ->class('text-lg font-semibold mb-2'),

            $count ? _FlexBetween(
                _Flex(_Sax('export-3', 18), _Html('navbar.see-all'))->class('items-center gap-2'),
                // White on bg-warning was unreadable (~1.8:1).
                _Pill($count)->class('bg-white text-greenmain !px-3'),
            )
                ->class('gap-2 mb-4 vlBtn items-center')
                // The page saves this session's search as a short ?link= (the whole state was in the URL).
                ->href('search.results', ['from' => $this->storeKey])
                ->inNewTab() : null,
        );
    }

    public function query()
    {
        // Too short a search is neither listed nor counted (SearchService::searchTextTooShort()): the empty state
        // says why. Kompo turns null into an empty collection.
        if (!$this->state || $this->searchTooShort()) {
            return null;
        }

        return $this->searchService->getQuery()?->take($this->perPage);
    }

    public function render($item)
    {
        $typeInstance = $this->state?->getSearchableInstanceForResultsPanel();
        $search = $this->state?->getSearch();

        // A keyboard item of the panel (searchbarClientJs(): Enter clicks it, a page or a modal).
        return $typeInstance->searchElement($item, $search)->class('!mb-2 searchbar-result searchbar-nav-item');
    }

    /**
     * An empty panel: no text, no filter pill (a pending one included: a filter is being added). Default rules alone
     * and a picked entity still show them.
     */
    protected function showsRecentSearches(): bool
    {
        return RecentSearches::enabled() && $this->state
            && trim((string) $this->state->getSearch()) === ''
            && $this->state->getFilterableRules()->isEmpty();
    }

    protected function searchTooShort(): bool
    {
        return $this->state && $this->searchService->searchTextTooShort($this->state->getSearch() ?? '');
    }
}
