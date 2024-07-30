<?php

namespace Kompo\Searchbar\SearchItems\Sections;

use Kompo\Searchbar\SearchItems\Filterables\FilterableSelectScope;
use Kompo\Searchbar\SearchItems\Sections\SearchSection;

class SearchSelectScopeSection extends SearchSection
{
	protected $filterableClass = FilterableSelectScope::class;
    protected FilterableSelectScope $filterable;
	protected $filterableKey;

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
		return $this->filterable->optionsScopes();
	}

	public function getRule($type)
	{
        return $this->filterable->getRuleInstance([
            'scope' => $type,
        ])->setKeyReference($this->filterableKey);
	}
}