<?php

namespace Kompo\Searchbar\Components;

use Kompo\Table;

class SearchResults extends Table
{
    const SEARCH_ID = 'searchTable';
    protected $state;
    protected $storeKey;

    public function created()
    {
        $this->storeKey = self::SEARCH_ID . time();

        searchService(self::SEARCH_ID)->setStoreKey($this->storeKey);
        stateStore(self::SEARCH_ID)->setFromRequest();
        $this->state = stateStore(self::SEARCH_ID)->getState();
    }

    public function top()
    {
        return _Html('filter.search-results')->class('text-2xl font-semibold mb-4');
    }

    public function query()
    {
        return !$this->state ? null : searchService(self::SEARCH_ID)->getQuery()?->take($this->perPage);
    }

    public function render()
    {
        $searchableI = $this->state->getSearchableInstance();

        return _Rows(
            _Html('filter.search-results')->class('text-2xl font-semibold mb-4'),
            $searchableI->getTableClassInstance([
                'storeKey' => $this->storeKey,
            ]),
        );
    }
}