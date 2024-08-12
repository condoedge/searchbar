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
        $count = $this->searchService->getQuery()?->count();
        return $count ? _Flex(
            _Html('navbar.see-all'),
            _Pill($count)->class('bg-warning text-white !px-3'),
        )->class('gap-2 mb-4 vlBtn')->href(
            'search.results', ['searchDetails' => compressArray($this->state->toArray())],
        )->inNewTab() : null;
    }

    public function query()
    {
        return !$this->state ? null : $this->searchService->getQuery()?->take($this->perPage);
    }

    public function render($item)
    {
        $typeInstance = $this->state?->getSearchableInstance();
        $search = $this->state?->getSearch();

        return $typeInstance->searchElement($item, $search)->class('!mb-2');
    }
}
