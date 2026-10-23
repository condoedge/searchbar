<?php

namespace Kompo\Searchbar\Components;

/**
 * Grouped actions (on the checked rows) of a table using HasSearchbarFilters; AbstractResultsTable uses it. It relies
 * on that trait's state, service and getServiceKey(), so it doesn't declare them.
 *
 * The checked ids come from the browser and are narrowed to the state's query on the searchable's baseSearchQuery().
 * A table whose rows come from its own base query (searchbarQuery(Person::where(...))) narrows them the same way:
 * groupedActionsQuery() here, resultsQuery() in its modals. The gate and the model security apply either way.
 *
 *     protected function groupedActionsOptions()
 *     {
 *         return [
 *             !$this->canRunGroupedAction('archive') ? null : _DropdownLink('Archive')->selfPost('getArchiveModal')
 *                 ->inModal()->config(['withCheckedItemIds' => true]),
 *         ];
 *     }
 *
 *     public function getArchiveModal()   // ArchiveModal extends AbstractGroupedActionModal, $groupedAction = 'archive'
 *     {
 *         return $this->searchbarGroupedActionModal(ArchiveModal::class);
 *     }
 */
trait HasSearchbarGroupedActions
{
    /**
     * GroupedActionGate on the table's entity: show the action only when it passes. The modal checks it again on
     * every call; an action that isn't a modal checks it itself.
     */
    protected function canRunGroupedAction(string $action): bool
    {
        return GroupedActionGate::allows($this->state?->getSearchableInstance(), $action);
    }

    /**
     * The checked ids (withCheckedItemIds) that are among the table's current results, for an action that isn't an
     * AbstractGroupedActionModal (a host form taking ids). None when more than AbstractGroupedActionModal::maxItemIds()
     * are checked: the action refuses them as a whole, it doesn't run on a part.
     */
    protected function selectedResultIds(): array
    {
        $ids = AbstractGroupedActionModal::parseItemIds(request('itemIds'));

        if (!$ids || AbstractGroupedActionModal::tooManyItemIds($ids) || $this->selectsEveryMatch() || !$this->state?->getSearchableEntity()) {
            return [];
        }

        // get(), not pluck(): a full-text query orders by its "relevance" alias and binds its select.
        return $this->groupedActionsQuery()->whereKey($ids)->get()->modelKeys();
    }

    /**
     * Kompo's selection bar picked "all" (_selection_mode=all: every matching row, its _excludedIds aside), while only
     * the checked rows of the page come as itemIds. Grouped actions run on checked rows only (owner decision: no
     * "apply to all N matches", a delete on every match is too risky): refused as a whole rather than silently run on
     * the page's rows.
     */
    protected function selectsEveryMatch(): bool
    {
        return request('_selection_mode') === 'all';
    }

    /**
     * The rows the checked ids are narrowed to: the state's query on the searchable's baseSearchQuery(). A table with
     * its own base query returns $this->searchService->getQuery(<that base>) (no take(): the ids are the limit).
     */
    protected function groupedActionsQuery()
    {
        return $this->searchService->getQuery();
    }

    /**
     * $modal (an AbstractGroupedActionModal) on the checked rows and this table's state, refreshing this table; a card
     * instead when nothing is checked, or more than AbstractGroupedActionModal::maxItemIds(). The modal narrows the ids
     * to the current results itself.
     */
    protected function searchbarGroupedActionModal(string $modal, array $props = [])
    {
        $ids = AbstractGroupedActionModal::parseItemIds(request('itemIds'));

        if (!$ids) {
            return _CardWhiteP4(_Html('filter.no-items-selected')->class('text-xl'))->class('!mb-0');
        }

        if (AbstractGroupedActionModal::tooManyItemIds($ids)) {
            return _CardWhiteP4(_Html(__('filter.too-many-items-selected', ['max' => AbstractGroupedActionModal::maxItemIds()]))
                ->class('text-xl'))->class('!mb-0');
        }

        if ($this->selectsEveryMatch()) {
            return _CardWhiteP4(_Html('filter.grouped-action-checked-rows-only')->class('text-xl'))->class('!mb-0');
        }

        return $this->instanciateSearchKomponent($modal, array_merge([
            'itemIds' => implode(',', $ids),
            'serviceKey' => $this->getServiceKey(),
            'refresh_id' => $this->id,
        ], $props));
    }
}
