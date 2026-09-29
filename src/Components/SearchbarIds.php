<?php

namespace Kompo\Searchbar\Components;

/**
 * Komponent ids of the navbar search: what a state change refreshes (SearchService::refreshTargets()). Never refresh a
 * komponent together with one of its ancestors: the ancestor's refresh replaces it, and its own response lands on a
 * destroyed instance.
 *
 *     NAVBAR (shell: pills, input, spinner, panel container)
 *     ├── PILLS           entity pill, rule pills and their editors; the chip they fold into while the panel is closed
 *     ├── INPUT           the search text and its #navbar-search-send link
 *     └── PANEL           top bar, results column, filters column
 *         ├── RESULTS     lazy-loaded into the results column when the panel opens, unmounted when it closes
 *         │   └── RECENTS the user's recent searches, while nothing is typed or filtered
 *         ├── FILTERS     sections, toggles, "Custom filters", or ENTITIES (no entity picked)
 *         │   └── ENTITIES
 *         └── FAVORITES
 *     CUSTOM_FILTERS      a modal (outside the navbar's tree)
 */
final class SearchbarIds
{
    const NAVBAR = 'navbar-search';
    const INPUT = 'navbar-search-box';
    const PILLS = 'navbar-search-pills';
    const PANEL = 'search-panel';
    // Not search-filters-panel: that is the filters column's DOM id.
    const FILTERS = 'search-filter-options';
    const RESULTS = 'enhanced-searchbar';
    const RECENTS = 'searchbar-recent-searches';
    const ENTITIES = 'searchable-options';
    const FAVORITES = 'search-favorites';
    const CUSTOM_FILTERS = 'custom-filters-modal';
}
