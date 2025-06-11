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
            'value' => $search,
        ])->setKeyReference($index);
	}

    protected function linkOption($option, $index)
    {
        return _Link($option)->post('searchstate.add-rule', ['rule' => serialize($this->getRule($index))])->withAllFormValues()->post('searchstate.clean-search')->withAllFormValues()->refresh('navbar-search');
    }
}