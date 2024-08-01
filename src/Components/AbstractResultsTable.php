<?php

namespace Kompo\Searchbar\Components;

use Kompo\Auth\Exports\TableExportableToExcel;
use Kompo\Searchbar\Components\SearchKomponentUtils;
use Kompo\Searchbar\Components\SearchResults;

class AbstractResultsTable extends TableExportableToExcel
{
        
    use SearchKomponentUtils;

    protected $filename = 'translate.search-results';

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
            _Dropdown('translate.grouped-actions')->button()->submenu($this->groupedActionsOptions()),
            _ExcelExportButton()->class('!mb-0 mt-3'),
        )->class('mb-4');
    }

    protected function groupedActionsOptions()
    {
        return [
            _Link('translate.delete')->selfPost('deleteEntities')->config(['withCheckedItemIds' => true])->refresh()->class('py-2 px-3'),
        ];
    }

    protected function checkboxGroupedActions($id)
    {
        return _Checkbox()->emit('checkItemId', ['id' => $id])->class('!mb-0 child-checkbox');
    }

    public function deleteEntities()
    {
        $this->searchableInstance->destroy(request('itemIds'));
    }
}