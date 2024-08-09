<?php

namespace Kompo\Searchbar\Components;

use Kompo\Form;

class SearchResults extends Form
{
    public $containerClass = 'container-table';

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

    public function render()
    {
        $searchableI = $this->state->getSearchableInstance();

        return _Rows(
            _Html(__(
                'filter.search-results.with-values', 
                ['entity' => $this->state->getSearchableInstance()?->searchableName()]
            ))->class('text-2xl font-semibold'),

            $this->state->getRules()->count() ? _Flex($this->state->getRules()->map(fn($rule, $i) => $rule->render($i, false)))->class('gap-2 mb-3 mt-4') : null,

            $searchableI->getTableClassInstance([
                'storeKey' => $this->storeKey,
            ]),
        );
    }
}