<?php

namespace Kompo\Searchbar\Components;

use Kompo\Query;

class EnhancedSearchbar extends Query
{
    use SearchKomponentUtils;

    public $perPage = 6;
    public $paginationType = 'Scroll';
    public $style = 'width: calc(100% - 5px)';

    public function noItemsFound()
    {
        return _Html('navbar.no-results')->class('m-4 p-4 card-level5');
    }

    public function top()
    {
        return $this->searchService->getQuery()?->count() > $this->perPage ? _LinkButton('navbar.see-all')->href(
            'search.results', $this->state->toArray(),
        )->class('mb-4')->inNewTab() : null;
    }

    public function query()
    {
        return !$this->state ? null : $this->searchService->getQuery()?->take($this->perPage);
    }

    public function render($item)
    {
        $searchableI = $this->state?->getSearchableInstance();
        $search = $this->state?->getSearch();

        return $searchableI->searchElement($item, $search)->class('mb-3');
    }
}
