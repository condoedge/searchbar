<?php

namespace App\Kompo\Search;

use Kompo\Searchbar\Components\SearchKomponentUtils;
use Kompo\Searchbar\Components\SearchResults;
use Kompo\Table;

class AbstractResultsTable extends Table
{
    use SearchKomponentUtils;

    public $class = 'pb-8';
    public $itemsWrapperClass = 'resultTable pb-2';

    protected string $serviceKey = SearchResults::SEARCH_ID;

    public function query()
    {
        return !$this->state ? null : searchService($this->serviceKey)->getQuery()?->take($this->perPage);
    }
}