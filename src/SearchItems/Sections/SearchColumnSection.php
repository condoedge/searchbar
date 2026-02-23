<?php

namespace Kompo\Searchbar\SearchItems\Sections;

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

    public function getRule($index)
	{
        $filterable = $this->getFilterable($index);
        $search = $this->searchContextService->getStore()->getState()?->getSearch();

		return $filterable->getRuleInstance([
            // 'value' => $search, For now putting this to null so we now the value is pending (We are going to open the input so they can change the value)
        ])->setKeyReference($index);
	}

    protected function linkOption($option, $index)
    {
        $isSelected = $this->isOptionSelected($index);

        $link = $isSelected
            ? _Link($option)->icon(_Sax('tick-circle', 16))->class($this->chipClasses($isSelected))
            : _Link($option)->icon(_Sax('search-normal-1', 14))->class($this->chipClasses($isSelected));

        if ($isSelected) {
            $ruleIndex = $this->getActiveFilterableRules()->search(fn($rule) => $rule->getKeyReference() === $index);

            return $link->post('searchstate.delete-rule', ['i' => $ruleIndex])
                ->withAllFormValues()
                ->refresh('navbar-search');
        }

        return $link->post('searchstate.add-rule', ['rule' => serialize($this->getRule($index))])
            ->withAllFormValues()
            ->refresh('navbar-search');
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