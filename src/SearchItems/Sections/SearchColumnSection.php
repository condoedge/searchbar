<?php

namespace Kompo\Searchbar\SearchItems\Sections;

use Kompo\Searchbar\SearchService;

class SearchColumnSection extends SearchSection
{
    protected $columns;

    /**
     * @param \Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumn[] $columns Columns to display and their corresponding rules
     */
    public function __construct(array $filterableCols)
    {
        $this->columns = collect($filterableCols);
    }

    public function options()
	{
		return collect($this->columns)->mapWithKeys(function ($column, $key) {
            $filterable = $this->getFilterable($column);

            return [$column => $filterable->getName()];
        });
	}

    /** A pending rule of that column (its pill opens the editor). */
    public function getRule($index)
	{
		return $this->getFilterable($index)->getRuleInstance([
            'value' => null,
        ])->setKeyReference($index);
	}

    /**
     * The typed search text becomes this field's filter, or the field's editor opens: the server decides from the
     * key alone (SearchStateController::columnChip).
     */
    protected function linkOption($option, $index)
    {
        $isSelected = $this->isOptionSelected($index);

        // Same icon size in both states: the chip width jumped when toggled.
        $link = _Link($option)->icon(_Sax($isSelected ? 'tick-circle' : 'search-normal-1', 16))
            ->class($this->chipClasses($isSelected))
            ->title($isSelected ? 'filter.chip-selected-hint' : 'filter.chip-hint');

        // It may consume the typed text: the input is refreshed too.
        return $this->busyAction($link, 'searchstate.column-chip', ['key' => $index], SearchService::CHANGE_TEXT_TO_RULE);
    }

    public function isOptionSelected($index): bool
    {
        return $this->getActiveFilterableRules()->contains(function ($rule) use ($index) {
            return $rule->getKeyReference() === $index;
        });
    }

    protected function getSectionLabel(): ?string
    {
        return 'filter.search-by';
    }
}