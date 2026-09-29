<?php

namespace Kompo\Searchbar\Components;

use Condoedge\Utils\Kompo\Common\Table;
use Kompo\Searchbar\Components\SearchKomponentUtils;
use Kompo\Searchbar\Components\SearchResults;
use Kompo\Searchbar\SearchItems\Stores\LinkStore;

/**
 * The results table of the "Open in a table" page (host tables extend it). Its filters are the searchbar's, on the
 * page's saved link (HasSearchbarFilters): search box, "+ Filter" and editable pills, refreshing this table.
 */
class AbstractResultsTable extends Table
{
    use HasSearchbarFilters;
    use HasSearchbarGroupedActions;

    const ID = 'abstract-results-table';
    public $id = self::ID;

    protected $filename = 'filter.search-results';

    public $class = 'pb-8';
    public $itemsWrapperClass = 'resultTable pb-2';

    // The app's white table style (condoedge utils EnableWhiteTableStyle plugin).
    protected $isWhiteTable = true;

    protected string $serviceKey = SearchResults::SEARCH_ID;

    /** Its state is the page's link (LinkStore, ?link=): never a remembered table state, whatever the config. */
    protected function searchbarRememberKey(): ?string
    {
        return null;
    }

    public function query()
    {
        // The results page always stores an explicit entity: without one the state is gone (pruned link), and the
        // default entity's rows must not show under this table's columns.
        return !$this->state?->getSearchableEntity() ? null : $this->searchbarQuery()?->take($this->perPage);
    }

    /** One white card: search box and filters, the pills, then the table's actions. */
    public function top()
    {
        // Host tables merge their own options with the parent's: an action the user may not run is null.
        $actions = collect($this->groupedActionsOptions())->filter()->values()->all();

        return _Rows(
            $this->searchbarFilters(),
            _FlexBetween(
                // No action the user may run: no empty menu (the empty element keeps the export button on the right).
                !$actions ? _Html() : _Dropdown('filter.grouped-actions')->button()->submenu($actions),
                // mt-3 put the export button lower than the dropdown.
                _ExcelExportButton()->class('!mb-0'),
            )->class('items-center gap-4 border-t border-gray-200 pt-3 mt-3'),
        )->class('bg-white rounded-2xl border-2 border-gray-300 p-4 mb-4');
    }

    protected function groupedActionsOptions()
    {
        return [
            !$this->canRunGroupedAction('delete') ? null
                : _DropdownLink('filter.delete')->selfPost('getDeleteConfirmModal')->inModal()->config(['withCheckedItemIds' => true])->class('py-2 px-3'),
        ];
    }

    /** Not exported: its column's heading goes too, marked or not (SearchbarTableExport). */
    protected function checkboxGroupedActions($id)
    {
        return _Rows(
                _Checkbox()->emit('checkItemId', ['id' => $id])->class('!mb-0 child-checkbox')
            )->stopPropagation()->class('exclude-export');
    }

    /**
     * "Share this view": the page's own link (its author keeps editing it, anyone else opening it gets a copy), no
     * snapshot row.
     */
    protected function searchbarShareUrl(): ?string
    {
        return \Route::has('search.results') && LinkStore::findLink($this->storeKey)
            ? route('search.results', ['link' => $this->storeKey])
            : null;
    }

    /** The file is named after the entity ("person-email-2026-09-28"), not the untranslated filter.search-results key. */
    protected function searchbarExportBaseName(): string
    {
        return (string) $this->state->getSearchableInstance()?->searchableName() ?: (string) __($this->filename);
    }

    public function getDeleteConfirmModal()
    {
        // The menu item is hidden without the permission, but a self-method name can be replayed from another page.
        if (!$this->canRunGroupedAction('delete')) {
            abort(403, __('filter.grouped-action-not-allowed'));
        }

        return $this->searchbarGroupedActionModal(ConfirmMultiDeleteModal::class);
    }
}
