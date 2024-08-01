<?php

namespace Kompo\Searchbar\Components;

use Kompo\Auth\Exports\TableExportableToExcel;
use Kompo\Searchbar\Components\SearchKomponentUtils;
use Kompo\Searchbar\Components\SearchResults;

class AbstractResultsTable extends TableExportableToExcel
{       
    use SearchKomponentUtils;

    const ID = 'abstract-results-table';
    public $id = self::ID;

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
            _Dropdown('filter.grouped-actions')->button()->submenu($this->groupedActionsOptions()),
            _ExcelExportButton()->class('!mb-0 mt-3'),
        )->class('mb-4');
    }

    protected function groupedActionsOptions()
    {
        return [
            _Link('filter.delete')->selfPost('getDeleteConfirmModal')->inModal()->config(['withCheckedItemIds' => true])->class('py-2 px-3'),
        ];
    }

    protected function checkboxGroupedActions($id)
    {
        return _Checkbox()->emit('checkItemId', ['id' => $id])->class('!mb-0 child-checkbox');
    }

    public function getDeleteConfirmModal()
    {
        if (!request('itemIds') || !count(request('itemIds'))) {
            return _CardWhiteP4(_Html('filter.no-items-selected')->class('text-xl'))->class('!mb-0');
        }

        return $this->instanciateSearchKomponent(ConfirmMultiDeleteModal::class, [
            'itemIds' => implode(',', request('itemIds')),
        ]);
    }
}