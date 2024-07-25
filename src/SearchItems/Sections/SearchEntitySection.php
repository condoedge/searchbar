<?php

namespace Kompo\Searchbar\SearchItems\Sections;

use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumn;

class SearchEntitySection extends SearchSection
{
	protected $filterableClass = FilterableColumn::class;
	protected FilterableColumn $filterable;
	protected string $filterableKey;

    public function __construct($filterableKey)
    {
		$this->filterableKey = $filterableKey;
    }

	public function created()
	{
		$this->filterable = $this->getFilterable($this->filterableKey);
	}

	public function options()
	{
		return $this->filterable->getEntityType()?->optionsWithLabels();
	}

	public function getRule($type)
	{
		return $this->filterable->getRuleInstance([
            'value' => [$type],
        ])->setKeyReference($this->filterableKey);
	}
}