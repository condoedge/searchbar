<?php

namespace Kompo\Searchbar\Components;

use Condoedge\Utils\Kompo\Common\Form;
use Condoedge\Utils\Kompo\Plugins\DebugReload;
use Kompo\Core\KompoAction;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;
use Kompo\Searchbar\SearchService;

/**
 * The navbar's entity pill and rule pills (their editors too), in their own komponent: a pill or chip change refreshes
 * it with the filters column and the results (SearchService::refreshTargets()), never the input next to it.
 *
 * While the panel is closed the pills fold into one chip, "Persons 2" (.searchbar-filters-count): in the navbar's
 * scrolling row they pushed the input aside and scrolled out of sight. Open, the pills come back (NavbarSearch's
 * searchbarSetCompact, re-applied by this komponent's load). A pill editor is never folded: the pills stay shown
 * until it is done, and the keyboard focus on a pill that folds away goes to the chip. The chip is in the arrow-key
 * order of the closed navbar (← at the start of the input, ↓ / → back to it: searchbarClientJs), and keeps the focus
 * when a refresh of this komponent replaces it. Open below md, this komponent is a row of its own, fixed under the
 * navbar above the panel, and after the navbar's row in the DOM (NavbarSearch's searchbarPlacePills, also run by this
 * komponent's load): next to the input the pills squeezed it to about 24px.
 */
class NavbarSearchPills extends Form
{
    use SearchStateRequestUtils;
    use SearchKomponentUtils;

    public $id = SearchbarIds::PILLS;

    public $class = 'shrink-0';

    // Not the local debug "reload" link (app.debug) of every komponent: in the navbar's nested komponents it took height in
    // the navbar row and covered the filters column's corner.
    protected $excludePlugins = [DebugReload::class];

    public function created()
    {
        $this->setSearchProps();

        // Every pill and chip action refreshes this komponent: its load ends the lock that action set (searchbarBusy),
        // and locks the new pills again while typed text is still on its way (the input isn't refreshed with them).
        // Its new nodes come as the server renders them (pills shown, chip hidden): folded again, before paint, while
        // the panel is closed. The keyboard focus they took (the chip's, a pill's) comes back (searchbarKbAfterRefresh).
        $this->onLoad(fn($e) => $e->run('() => { window.searchbarUnlock && searchbarUnlock(); window.searchbarRelockTyping && searchbarRelockTyping(); window.searchbarSetCompact && searchbarSetCompact(); window.searchbarKbAfterRefresh && searchbarKbAfterRefresh(); }'));
    }

    public function render()
    {
        // A relation select of a pill editor searching its options: Kompo boots this Form through render() just to
        // call searchbarRelationOptions(); rebuilding the pills (label queries, the editor's size check) ran on every
        // keystroke.
        if (KompoAction::is('search-options')) {
            return _Rows();
        }

        $searchableName = $this->state->getSearchableInstance()?->searchableName();
        $rules = $this->state->getRules();

        // Nothing to show: no pill row (its padding would push the input), no chip.
        if (!$searchableName && $rules->isEmpty()) {
            return _Rows();
        }

        return _Flex(
            $this->filtersCountChip($searchableName, static::appliedFiltersCount($rules)),
            _Flex(
                !$searchableName ? null : _RulePill($searchableName,
                    // The entity could only be cleared from the panel's Back link.
                    _Link()->icon('x')->class('opacity-60 hover:opacity-100 hover:text-danger')->title('filter.back')
                        ->onClick(fn($e) => $e->run('() => { window.searchbarBusy && searchbarBusy(); }')
                            && $e->post('searchstate.get-back', $this->searchService->stateParams())
                                ->refresh($this->searchService->refreshTargets(SearchService::CHANGE_ENTITY))),
                    icon: 'filter',
                ),
                // Addressed by their ids (FilterableRule::render()), not their positions.
                ...$rules->map(fn($rule) => $rule->render())
            )->class('search-rule-pills gap-2 px-2 items-center'),
        )->class('items-center');
    }

    /**
     * The filters the closed navbar's chip counts: every pill but a pending one (just added, no value yet: it filters
     * nothing), default rules included (they filter the results and show as pills).
     */
    public static function appliedFiltersCount($rules): int
    {
        return collect($rules)->reject(fn($rule) => $rule instanceof FilterableRule && $rule->isPendingValue())->count();
    }

    /**
     * The pills folded: the entity's name (else "Filters") and a green badge counting the applied filters (none: no
     * badge). Rendered hidden, the pills shown: that is the page without JS; searchbarSetCompact swaps them while the
     * panel is closed (the navbar's load and this komponent's run before paint). A click opens the panel, where the
     * pills are: through the input's focus, which opens it; an input still focused (Esc closed the panel) gets no
     * focus event, the panel is opened directly.
     */
    protected function filtersCountChip(?string $searchableName, int $count)
    {
        $badge = !$count ? '' : '<span class="ml-1 bg-greenmain text-white rounded-full text-xs font-semibold px-1.5 h-5 leading-5 inline-flex items-center justify-center">' . $count . '</span>';

        // A Kompo label is HTML (v-html): the entity's name is escaped.
        return _Link(e($searchableName ?: __('filter.filters')) . $badge)->icon(_Sax('filter', 16))
            // hidden comes after inline-flex in the compiled CSS: it wins until searchbarSetCompact removes it.
            ->class('searchbar-filters-count hidden inline-flex shrink-0 whitespace-nowrap items-center gap-1 text-sm bg-level4 text-level1 rounded-md px-2 py-1 mr-1 hover:bg-level5')
            ->title(trans_choice('filter.filters-applied', $count, ['count' => $count]) . ' · ' . __('filter.show-filters'))
            // It opens the panel: aria-expanded follows it (searchbarSetCompact).
            ->attr(['aria-controls' => 'search-panel-container'])
            ->run('() => {
                const input = document.querySelector("#navbar-search .navbar-search-input input");
                if (input && document.activeElement !== input) input.focus();
                else window.openSearchPanel && openSearchPanel();
            }');
    }
}
