<?php

namespace Kompo\Searchbar\Components;

use Kompo\Form;

class SearchResults extends Form
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

    public function render()
    {
        $searchableI = $this->state->getSearchableInstance();

        return _Rows(
            _Html(__(
                'translate.with-values.filter.search-results', 
                ['entity' => $this->state->getSearchableInstance()?->searchableName()]
            ))->class('text-2xl font-semibold mb-4'),

            _Flex($this->state->getRules()->map(fn($rule, $i) => $rule->render($i, false)))->class('gap-3 mb-3'),

            $searchableI->getTableClassInstance([
                'storeKey' => $this->storeKey,
            ]),
        );
    }
}