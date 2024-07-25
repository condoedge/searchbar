<?php

namespace Kompo\Searchbar\SearchItems\Sections;

use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\SearchItems\SearchItem;
use Exception;

abstract class SearchSection extends SearchItem
{
    protected $filterableClass = Filterable::class;

    protected function getFilterable($key): Filterable|Exception
	{
        $filterable = $this->searchContextService->getStore()->getState()->getSearchableInstance()->filterable($key);

        if(!$filterable instanceof $this->filterableClass) {
            throw new Exception('Filterable must be an instance of ' . $this->filterableClass);
        }

		return $filterable;
	}

    public function showOptions()
    {
        return _Rows(
            $this->options()->map(function($option, $index) {
                return $this->linkOption($option, $index);
            }),
        );
    }

    protected function linkOption($option, $index)
    {
        return _Link($option)->selfPost('addRule', ['rule' => serialize($this->getRule($index))])->refresh('navbar-search');
    }

	abstract public function getRule($type);

    abstract function options();
}