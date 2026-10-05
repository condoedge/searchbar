<?php

namespace Kompo\Searchbar\Components;

use Condoedge\Utils\Kompo\Common\Form;
use Condoedge\Utils\Kompo\Plugins\DebugReload;
use Kompo\Searchbar\SearchItems\Stores\RecentSearches;
use Kompo\Searchbar\SearchService;

/**
 * The user's recent searches (RecentSearches), on top of the navbar panel's results column while nothing is typed or
 * filtered (EnhancedSearchbar). A row puts the whole search back (entity, filters, text), as a favorite does; ✕ forgets
 * it, "Clear" forgets them all (this list only is refreshed). The rows are keyboard items of the results column
 * (searchbar-nav-item): ↓ from the input reaches them first. Typing hides the list at once (searchbarClientJs()), before
 * the results' refresh stops rendering it.
 */
class RecentSearchesList extends Form
{
    use SearchStateRequestUtils;
    use SearchKomponentUtils;

    public $id = SearchbarIds::RECENTS;

    public $class = 'searchbar-recent';

    // Not the local debug "reload" link (app.debug) of every komponent: it took room in the results column.
    protected $excludePlugins = [DebugReload::class];

    public function created()
    {
        $this->setSearchProps();

        // Refreshed by its ✕ / "Clear" while text was typed: hidden again. An outlined row that went is let go.
        $this->onLoad(fn($e) => $e->run('() => { window.searchbarHideRecents && searchbarHideRecents(); window.searchbarKbAfterRefresh && searchbarKbAfterRefresh(); }'));
    }

    public function render()
    {
        $recents = RecentSearches::forUser();

        if ($recents->isEmpty()) {
            return _Rows();
        }

        return _Rows(
            _FlexBetween(
                _Html('filter.recent-searches')->class('text-xs font-semibold text-gray-500 uppercase tracking-wide'),
                _Link('filter.recent-clear')->class('text-xs text-gray-500 hover:text-danger')
                    ->selfPost('clearRecent')->refresh(),
            )->class('items-center gap-2 mb-1 px-2'),
            // Spread: Kompo takes a collection as the elements only when it is the one argument.
            ...$recents->map(fn($recent) => $this->recentRow($recent))->all(),
        )->class('mb-3');
    }

    /**
     * Its text (a filters-only search: its filters, else "Filters only"), then its entity and how many filters it had
     * (as the closed navbar's chip counts them). User text and host labels: escaped (a Kompo label is HTML).
     */
    protected function recentRow($recent)
    {
        $text = $recent->name !== '' ? $recent->name
            : ($recent->filters ? implode(', ', $recent->filters) : __('filter.recent-no-text'));
        $meta = collect([
            $recent->entity,
            $recent->filtersCount ? trans_choice('filter.filters-applied', $recent->filtersCount, ['count' => $recent->filtersCount]) : null,
        ])->filter()->map(fn($part) => e($part))->implode(' · ');
        $title = collect([$recent->name, ...$recent->filters])->filter(fn($part) => $part !== '')->implode(' · ');

        // The text first: it keeps its width (up to the row's), the meta gets what is left (a phone cuts it).
        return _Flex(
            _Link('<span class="truncate shrink-0 max-w-full">' . e($text) . '</span>'
                    . ($meta === '' ? '' : '<span class="truncate min-w-0 text-xs text-gray-500">' . $meta . '</span>'))
                ->icon(_Sax('clock', 16))
                // A keyboard item of the results column (searchbarClientJs()): Enter puts the search back.
                ->class('searchbar-nav-item searchbar-recent-item flex-1 min-w-0 inline-flex items-center gap-2 text-sm px-2 py-1.5 rounded-lg hover:bg-level4')
                // Not title(): it translates (user text could be a translation key).
                ->attr(['title' => $title])
                // It replaces the entity, the rules and the text: the whole navbar, as a favorite.
                ->onClick(fn($e) => $e->run('() => { window.searchActiveTab = "filters"; window.searchbarBusy && searchbarBusy(); }')
                    && $e->selfPost('loadRecent', ['id' => $recent->id])->refresh($this->searchService->refreshTargets(SearchService::CHANGE_ALL))),
            _Link()->icon('x')->class('searchbar-recent-forget text-gray-400 hover:text-danger shrink-0 px-2')->title('filter.recent-remove')
                // Only this list changes.
                ->selfPost('forgetRecent', ['id' => $recent->id])->refresh(),
        )->class('items-center gap-1 min-w-0');
    }

    /** One of the user's own recent searches into the navbar's state (RecentSearches: any other id changes nothing). */
    public function loadRecent()
    {
        RecentSearches::restore(request('id'), $this->searchService);
    }

    public function forgetRecent()
    {
        RecentSearches::forget(request('id'));
    }

    public function clearRecent()
    {
        RecentSearches::clear();
    }
}
