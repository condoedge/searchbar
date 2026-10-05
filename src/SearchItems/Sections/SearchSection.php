<?php

namespace Kompo\Searchbar\SearchItems\Sections;

use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumn;
use Kompo\Searchbar\SearchItems\Rules\ColumnRule\WithEntityRule;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;
use Kompo\Searchbar\SearchItems\Rules\RulesService;
use Kompo\Searchbar\SearchItems\SearchItem;
use Kompo\Searchbar\SearchService;
use Exception;

abstract class SearchSection extends SearchItem
{
    protected $filterableClass = Filterable::class;

    // Its options, evaluated once: a table's "Filter" menu counts them before showing them (a relation's are a query).
    protected $shownOptions = null;

    // In a table's "Filter" menu (HasSearchbarFilters): how its chips post (the table's state, lock and refresh).
    protected ?\Closure $tableAction = null;

    protected function getFilterable($key): Filterable|Exception
	{
        $filterable = $this->searchContextService->getStore()->getState()->getSearchableInstance()->filterable($key);

        if(!$filterable instanceof $this->filterableClass) {
            throw new Exception('Filterable must be an instance of ' . $this->filterableClass);
        }

		return $filterable;
	}

    /**
     * Shown in a table's "Filter" menu: its chips post through $action($el, $route, $params), the table's own
     * searchbarAction() (its state keys, the searchbar-for-{scope} class the teleported menu needs for the lock, and a
     * refresh of the table), instead of refreshing the navbar.
     */
    public function inTable(\Closure $action): static
    {
        $this->tableAction = $action;

        return $this;
    }

    /**
     * More than $max options (a table's "Filter" menu shows short sections only). A relation list that searches on
     * the server has hundreds of records: not loaded to be counted.
     */
    public function hasMoreOptionsThan(int $max): bool
    {
        // The filter of an option section (SearchEntitySection): its relation list may be huge.
        $filterable = property_exists($this, 'filterable') && isset($this->filterable) ? $this->filterable : null;

        if ($filterable instanceof FilterableColumn && $filterable->getEntityType()?->searchesOnServer()) {
            return true;
        }

        return $this->optionsOnce()->count() > $max;
    }

    /** options(), evaluated once per section. */
    public function optionsOnce()
    {
        return $this->shownOptions ??= collect($this->options());
    }

    public function showOptions()
    {
        return _Rows(
            $this->getSectionLabel() ? _Html($this->getSectionLabel())->class('text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2') : null,
            _Flex(
                $this->optionsOnce()->map(function($option, $index) {
                    return $this->linkOption($option, $index);
                }),
            )->class('flex-wrap gap-2'),
        );
    }

    protected function linkOption($option, $index)
    {
        $isSelected = $this->isOptionSelected($index);

        $link = $isSelected
            ? _Link($option)->icon(_Sax('tick-circle', 16))
            : _Link($option);

        return $this->busyAction($link, 'searchstate.toggle-section-rule', $this->chipParams($index))
            ->class($this->chipClasses($isSelected));
    }

    /**
     * What an option chip posts to toggle-section-rule. The package's sections post their filter key and the option
     * (the server builds the rule, SearchStateController::toggleSectionRule()); a host section's rule is posted
     * signed, unserialized when posted back (RulesService::retrieveRuleFromRequest()).
     */
    protected function chipParams($index): array
    {
        return ['rule' => RulesService::encodeRule($this->getRule($index))];
    }

    /**
     * Posts a state change for this section's state (its keys in the URL), locking the navbar until the komponents the
     * change touches are refreshed (see searchbarBusy()): the pills, the filters column and the results, not the input
     * or the panel ($change: SearchService::refreshTargets()).
     */
    protected function busyAction($el, string $route, array $params = [], string $change = SearchService::CHANGE_RULES)
    {
        if ($this->tableAction) {
            return ($this->tableAction)($el, $route, $params);
        }

        return $el->onClick(fn($e) => $e->run('() => { window.searchbarBusy && searchbarBusy(); }')
            && $e->post($route, $this->searchContextService->stateParams($params))->withAllFormValues()
                ->refresh($this->searchContextService->refreshTargets($change)));
    }

    protected function chipClasses($isSelected)
    {
        // The navbar panel's chips are keyboard items (searchbarClientJs()); a table menu's are not.
        $base = ($this->tableAction ? '' : 'searchbar-nav-item ') . 'rounded-lg px-3 py-1.5 border text-sm cursor-pointer transition-colors';

        return $isSelected
            ? $base . ' bg-greenmain text-white border-greenmain font-semibold'
            : $base . ' bg-level4 text-level1 border-level4 hover:border-greenmain hover:bg-level5';
    }

    public function isOptionSelected($index): bool
    {
        return false;
    }

    protected function getSectionLabel(): ?string
    {
        return null;
    }

    /**
     * The state's filter rules a chip's selected state reads. An option chip (SearchEntitySection) is its field's
     * IN rule: a "not in" / "different" rule holding the option isn't that chip (it showed selected, and a click
     * then removed the exclusion instead of adding the option).
     */
    protected function getActiveFilterableRules()
    {
        $rules = $this->searchContextService->getStore()->getState()->getFilterableRules();

        return $this instanceof SearchEntitySection
            ? $rules->reject(fn($rule) => $rule instanceof WithEntityRule && $rule->getOperator()?->negative() !== null)
            : $rules;
    }

	abstract public function getRule($type);

    abstract function options();
}
