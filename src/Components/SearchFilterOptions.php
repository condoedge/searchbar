<?php

namespace Kompo\Searchbar\Components;

use Condoedge\Utils\Kompo\Common\Form;
use Condoedge\Utils\Kompo\Plugins\DebugReload;
use Kompo\Searchbar\SearchItems\Rules\PremadeRuleWrapper;
use Kompo\Searchbar\SearchItems\Sections\SearchColumnSection;

/**
 * The panel's filters column: the entity's sections (chips), its default-rule toggles and "Custom filters", or, before
 * an entity is picked, the entity list (SearchableOptions). Its own komponent: a chip, toggle or pill change refreshes
 * it with the pills and the results, not the panel (no results skeleton, the tab and scroll stay).
 */
class SearchFilterOptions extends Form
{
    use SearchStateRequestUtils;
    use SearchKomponentUtils;

    public $id = SearchbarIds::FILTERS;

    // Not the local debug "reload" link (app.debug) of every komponent: in the navbar's nested komponents it took height in
    // the navbar row and covered the filters column's corner.
    protected $excludePlugins = [DebugReload::class];

    public function created()
    {
        $this->setSearchProps();

        // Chip, toggle and pill actions refresh this komponent (or the panel holding it): its load ends their lock, and
        // disables its new links again while typed text is still on its way (a "Search by" chip would apply the older).
        // A chip changed with the keyboard is outlined again (searchbarKbAfterRefresh).
        $this->onLoad(fn($e) => $e->run('() => { window.searchbarUnlock && searchbarUnlock(); window.searchbarRelockTyping && searchbarRelockTyping(); window.searchbarKbAfterRefresh && searchbarKbAfterRefresh(); }'));
    }

    public function render()
    {
        $typeInstance = $this->state->getSearchableInstance();

        return $typeInstance ? $this->sections($typeInstance) : _Rows(
            // Outside SearchableOptions: that komponent is refreshed on input.
            _Html('filter.pick-entity-hint')->class('searchbar-pick-entity-hint text-xs text-gray-500 px-5 pt-3 pb-1'),
            $this->instanciateSearchKomponent(SearchableOptions::class),
        );
    }

    protected function sections($searchableI)
    {
        $rulesCount = $this->state->getFilterableRules()->count();
        $sections = $searchableI->decoratedSections();
        $premade = collect($searchableI->getPremadeRules());
        // A quick toggle switched on filters too (it shows as a pill); the rules applied by default don't.
        $filtered = $this->state->getRules()->contains(fn($rule) => !($rule instanceof PremadeRuleWrapper && $rule->isDefault()));

        return _Rows(
            // Until a filter is added.
            $filtered ? null : $this->filtersHint($sections, $premade),
            _Rows($sections->map(fn($s) => $s->showOptions()->class('border-b py-4 border-level4'))),
            _Rows($premade->map(fn($r) => $r->getToggle()))->class('py-3'),
            // The count shows that filters are applied. A keyboard item of the panel (searchbar-nav-item), as the chips.
            _Link(__('filter.custom-filters') . ($rulesCount ? " ({$rulesCount})" : ''))->icon(_Sax('add-circle', 16))->class('searchbar-nav-item bg-greenmain text-white font-semibold rounded-lg px-3 py-2 text-sm mt-2 inline-flex items-center gap-2 hover:bg-opacity-90')
                ->selfGet('getCustomFiltersModal')->inModal(),
        )->class('pl-4');
    }

    /**
     * What filters do, shown until one is added. "Search by" is only mentioned when the entity has that section, and
     * the other labels are interpolated (a host may rename "Custom filters" or "Search by").
     */
    protected function filtersHint($sections, $premade)
    {
        $hint = $sections->isEmpty() && $premade->isEmpty()
            ? [__('filter.filters-empty-no-quick', ['custom' => __('filter.custom-filters')])]
            : [
                __('filter.filters-empty-hint'),
                $sections->contains(fn($s) => $s instanceof SearchColumnSection)
                    ? __('filter.filters-empty-search-by', ['section' => __('filter.search-by')]) : null,
            ];

        return _Flex(
            _Sax('info-circle', 16)->class('text-greenmain shrink-0 mt-0.5'),
            _Rows(
                _Html('filter.filters-empty-title')->class('font-semibold text-level1'),
                ...collect($hint)->filter()->map(fn($line) => _Html($line)->class('text-gray-500')),
            )->class('min-w-0'),
        )->class('searchbar-filters-hint gap-2 items-start bg-level5 bg-opacity-40 rounded-xl p-3 mr-4 mt-3 text-sm');
    }

    public function getCustomFiltersModal()
    {
        return $this->instanciateSearchKomponent(CustomFiltersModal::class);
    }
}
