<?php

namespace Kompo\Searchbar\Components;

use Kompo\Auth\Exports\TableExportableToExcel;
use Kompo\Searchbar\Components\SearchKomponentUtils;
use Kompo\Searchbar\Components\SearchResults;

class AbstractResultsTable extends TableExportableToExcel
{
        
    use SearchKomponentUtils;

    protected $filename = 'filter.search-results';

    public $class = 'pb-8';
    public $itemsWrapperClass = 'resultTable pb-2';

    protected string $serviceKey = SearchResults::SEARCH_ID;

    public function query()
    {
        return !$this->state ? null : searchService($this->serviceKey)->getQuery()?->take($this->perPage);
    }

    public function top()
    {
        return _FlexBetween(
            _Button('filter.grouped-actions'),
            _ExcelExportButton()->class('!mb-0 mt-3'),
        )->class('mb-4');
    }
}