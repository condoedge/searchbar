<?php

namespace Kompo\Searchbar\Components;

use Condoedge\Utils\Kompo\Common\Form;

class SearchResults extends Form
{
    public $containerClass = 'container-table';

    const SEARCH_ID = 'searchTable';
    protected $state;
    protected $storeKey;
    protected $searchService;

    public function created()
    {
        $this->storeKey = self::SEARCH_ID . time();

        $this->searchService = searchService(self::SEARCH_ID)->setStoreKey($this->storeKey);
        $this->searchService->getStore()->setFromRequest('searchDetails');
        $this->state = stateStore(self::SEARCH_ID)->getState();
    }

    public function render()
    {
        $typeInstance = $this->state->getSearchableInstanceForResultsPanel();

        return _Rows(
            _Html(__(
                'filter.search-results.with-values', 
                ['entity' => $typeInstance?->searchableName()]
            ))->class('text-2xl font-semibold'),

            $this->searchService->getQueryRules()->count() ? _Flex($this->searchService->getQueryRules()->map(fn($rule, $i) => $rule->render($i, false)))->class('gap-2 mb-3 mt-4') : null,

            !$typeInstance ? null : $typeInstance->getTableClassInstance([
                'storeKey' => $this->storeKey,
            ]),
        );
    }
}