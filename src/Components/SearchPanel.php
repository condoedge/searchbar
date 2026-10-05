<?php

namespace Kompo\Searchbar\Components;

use Condoedge\Utils\Kompo\Common\Form;
use Kompo\Searchbar\SearchService;

/**
 * The navbar's panel: the top bar (Back, tabs, retract arrow), the results column (lazy-loaded EnhancedSearchbar) and
 * the filters column (SearchFilterOptions, FavoritesSearches). Refreshed whole when the entity changes; chip, toggle
 * and pill changes refresh its filters and results komponents only (SearchService::refreshTargets()).
 */
class SearchPanel extends Form
{
    use SearchStateRequestUtils;
    use SearchKomponentUtils;

    public $id = SearchbarIds::PANEL;

    public function created()
    {
        $this->setSearchProps();
    }

    public function render()
    {
        $typeInstance = $this->state->getSearchableInstance();
        $sk = $this->serviceKey;
        $bodyId = 'search-results-lazy-body-' . $sk;
        $loaderId = 'search-results-loader-' . $sk;
        $tabClass = 'font-semibold text-lg cursor-pointer select-none px-3 py-1 rounded-lg transition-all search-tab-btn';

        return _Rows(
            // TOP BAR: Back button (left) + Toggle tabs + retract arrow (right)
            _FlexBetween(
                $typeInstance ? _Link('filter.back')->icon(_Sax('arrow-left', 20))->class('text-black font-semibold')
                    ->onClick(fn($e) => $e->run('() => { window.searchbarBusy && searchbarBusy(); }')
                        && $e->post('searchstate.get-back', $this->searchService->stateParams())
                            ->refresh($this->searchService->refreshTargets(SearchService::CHANGE_ENTITY))) : _Rows(),
                // The keys of the panel (searchbarClientJs()), from xl: a keyboard is likely there, and room. From md
                // it was cut off at lg (about 160px between Back and the tabs with SISC's sidebar), in both languages.
                _Html('filter.keyboard-hint')->class('searchbar-keyboard-hint hidden xl:block min-w-0 truncate mx-3 text-xs text-gray-400'),
                _Flex(
                    _Flex(
                        // search-tab-active was never defined: the active tab looked like the others.
                        _Link('filter.filters')->icon(_Sax('filter', 16))->class($tabClass . ' bg-white shadow-sm text-greenmain')
                            ->id('search-tab-filters')
                            ->run('() => { switchSearchTab("filters", true); }'),
                        _Link('filter.favorites')->icon(_Sax('star', 16))->class($tabClass . ' text-gray-400')
                            ->id('search-tab-favorites')
                            ->run('() => { switchSearchTab("favorites", true); }'),
                    )->class('items-center gap-1 bg-gray-100 rounded-lg p-0.5'),
                    // An icon only: named for assistive tech, aria-expanded set with the column (setSearchFiltersVisible).
                    _Link()->icon(_Sax('arrow-right', 16))->class('transition-transform cursor-pointer select-none ml-2 bg-greenmain text-white rounded-md w-8 h-8 flex items-center justify-center hover:bg-opacity-90')->id('search-filters-arrow')
                        ->title('filter.toggle-filters')->attr(['aria-controls' => 'search-filters-panel'])
                        ->run('() => { toggleSearchFilters(); }'),
                )->class('items-center'),
            // Stacked (below md), it sticks while the panel scrolls (NavbarSearch's setSearchFiltersVisible).
            )->id('search-panel-top')->class('px-4 py-2 bg-white'),

            // MAIN: Results (LEFT) + Filters/Favorites (RIGHT) from md; below, the filters go under the results
            // (widths, border side and scrolling set by NavbarSearch's setSearchFiltersVisible).
            _Flex(
                // LEFT: results column — lazy. Skeleton paints instantly; the full
                // EnhancedSearchbar (entity header + CTA + rows) streams in via selfGet ->
                // inPanel. Requested when the panel is shown (openSearchPanel), not on
                // every page view: the navbar is on every page, the panel mostly closed.
                _Rows(
                    _Panel(
                        $this->resultsSkeleton(),
                    )->id($bodyId)->class('w-full'),
                    _Link()->id($loaderId)->class('hidden')->selfGet('loadResultsColumn')->inPanel($bodyId),
                    // Requested after this panel (re)loaded, so after any change: no reload owed (searchbarResultsLoaded).
                    // searchbarParkResults(), the panel closed (NavbarSearch's closeSearchPanel, results landing
                    // closed, this panel loading closed): a pill change in the closed navbar refreshed the hidden
                    // results too (their query, and the pills waited for it). Kompo lists a komponent as live until
                    // it is refreshed, even once unmounted (it still answered the refreshes): the results' id is taken
                    // off Kompo's live list (an instance mounting later adds it back), and mounted results give way to
                    // the skeleton, through Kompo; the next opening loads them again. The skeleton is the one this
                    // panel rendered (its FormPanel's elements), not a copy in the page (the navbar is on every page);
                    // without it the column is emptied.
                    _Hidden()->onLoad(fn($e) => $e->run('(ctx) => {
                        window.searchResultsRequested = false;
                        window.searchbarResultsDirty = false;
                        window.loadSearchResults = () => {
                            if (window.searchResultsRequested) return;
                            window.searchResultsRequested = true;
                            document.getElementById("' . $loaderId . '")?.click();
                        };
                        const body = document.getElementById("' . $bodyId . '");
                        const panel = body && body.__vue__;
                        const skeleton = panel && Array.isArray(panel.elements) && !body.querySelector(".searchbar-results") ? JSON.stringify(panel.elements) : "[]";
                        const kompo = ctx && ctx.$k && ctx.$k.$kompo && typeof ctx.$k.$kompo.vlFillPanel === "function" ? ctx.$k.$kompo : null;
                        window.searchbarParkResults = () => {
                            if (window.navbar_search_opened) return false;
                            const current = document.getElementById("' . $bodyId . '");
                            const mounted = !!(current && current.querySelector(".searchbar-results"));
                            // Without Kompo\'s panel fill (an older front end), mounted results stay, and stay refreshed.
                            if (mounted && !kompo) return false;
                            const live = window._kompo && window._kompo.komponents;
                            if (Array.isArray(live)) window._kompo.komponents = live.filter((id) => id !== "' . SearchbarIds::RESULTS . '");
                            if (!mounted) return false;
                            window.searchResultsRequested = false;
                            window.searchbarResultsDirty = false;
                            kompo.vlFillPanel("' . $bodyId . '", JSON.parse(skeleton), {});
                            return true;
                        };
                        if (window.navbar_search_opened) window.loadSearchResults();
                        else window.searchbarParkResults();
                    }')),
                )->id('search-results-column')
                  ->class('relative items-start !pb-2 !pt-0 py-4 px-4 flex-1 min-w-0 self-stretch overflow-y-auto mini-scroll')
                  ->class($this->state->getSearchableEntity() ? '' : '-mt-8'),

                // RIGHT: filters/favorites panel
                _Rows(
                    _Rows(
                        $this->instanciateSearchKomponent(SearchFilterOptions::class),
                    )->id('search-content-filters')->class('overflow-y-auto overflow-x-hidden mini-scroll flex-1'),

                    _Rows(
                        $this->instanciateSearchKomponent(FavoritesSearches::class),
                    )->id('search-content-favorites')->class('px-2 py-2 overflow-y-auto mini-scroll flex-1 min-h-[40vh] md:min-h-[30vh] lg:min-h-[35vh]')->style('display:none'),
                )->class('overflow-y-auto overflow-x-hidden mini-scroll shrink-0 border-l border-level4 self-stretch')->id('search-filters-panel')->style('width:33.333%;transition:width 0.25s ease,opacity 0.2s ease,margin-right 0.25s ease'),
            )->id('search-panel-main')->class('w-full flex-1 flex-col md:flex-row')->style('max-height: 95vh;'),

            // A panel refresh (an entity picked, Back) gave a new top bar and columns in the same container: the tab,
            // the filters column's layout and the kept scroll are applied to them at once (NavbarSearch's helpers,
            // defined by the navbar's load: on the page's first load they come after this, which the timer covers).
            _Hidden()->onLoad(fn($e) => $e->run('() => { window.searchbarPanelLoaded && searchbarPanelLoaded(); setTimeout(() => {
                window.searchbarLoadingOff ? searchbarLoadingOff("search-panel-loading' . $sk . '") : (window.searchLoadingOff && searchLoadingOff("search-panel-loading' . $sk . '"));
                if (typeof switchSearchTab === "function") switchSearchTab(window.searchActiveTab || "filters");
                if (typeof setSearchFiltersVisible === "function") setSearchFiltersVisible(window.searchFiltersVisible !== false);
            }, 100)}')),

            // The container's width, not the screen's (w-screen): with a classic scrollbar, the stacked panel's
            // right edge was cut off.
        )->class('max-w-6xl w-full h-full md:h-auto bg-white rounded-b-2xl shadow-xl border-b border-l border-r border-level4 px-2 py-2 -mt-2');
    }

    public function loadResultsColumn()
    {
        return $this->instanciateSearchKomponent(EnhancedSearchbar::class);
    }

    /** Same shape as a result card (cardLevel4, round avatar and pill), in neutral gray. */
    protected function resultsSkeleton()
    {
        $bar = 'animate-pulse rounded-lg bg-gray-200';
        $card = fn() => _Flex(
            _Rows()->class("$bar w-8 h-8 rounded-full shrink-0"),
            _Rows(
                _Rows()->class("$bar h-4 w-40 mb-2"),
                _Rows()->class("$bar h-3 w-56 mb-2 opacity-60"),
                _Rows()->class("$bar h-3 w-32 opacity-60"),
            )->class('flex-1 min-w-0'),
            _Rows()->class("$bar h-6 w-24 self-start shrink-0 rounded-full"),
        )->class('gap-3 p-3 mb-2 rounded-2xl bg-level4 bg-opacity-40 items-center');

        return _Rows(
            _Rows()->class("$bar h-5 w-28 mb-3"),
            _Rows()->class("$bar h-12 w-full mb-4 rounded-xl"),
            $card(), $card(), $card(), $card(),
        );
    }

    /** @deprecated "Custom filters" is SearchFilterOptions' (kept for pages rendered before the split). */
    public function getCustomFiltersModal()
    {
        return $this->instanciateSearchKomponent(CustomFiltersModal::class);
    }
}
