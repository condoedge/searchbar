<?php

namespace Kompo\Searchbar\Components;

use Kompo\Table;

class SearchResults extends Table
{
    protected $state;
    protected $searchId;

    public function created()
    {
        $this->searchId = 'searchTable' . time();

        searchService('searchTable')->setStoreKey($this->searchId);
        stateStore('searchTable')->setFromRequest();
        $this->state = stateStore('searchTable')->getState();
    }

    public function top()
    {
        return _Html('translate.search-results')->class('text-2xl font-semibold mb-4');
    }

    public function query()
    {
        return !$this->state ? null : searchService('searchTable')->getQuery()?->take($this->perPage);
    }

    public function render($item)
    {
        $searchableI = $this->state->getSearchableInstance();
        $search = $this->state->getSearch();

        return $searchableI->searchElement($item, $search)->class('mb-3');
    }
}