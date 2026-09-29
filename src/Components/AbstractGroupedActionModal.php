<?php

namespace Kompo\Searchbar\Components;

use Condoedge\Utils\Kompo\Common\Modal;
use Illuminate\Support\Collection;

/**
 * A grouped action on a table's checked rows (ConfirmMultiDeleteModal, host actions). Opened by the table with
 * HasSearchbarGroupedActions::searchbarGroupedActionModal(): props 'itemIds' (the checked ids), 'serviceKey' and
 * 'storeKey' (the table's search state), 'refresh_id' (the table to refresh). Props are Kompo's encrypted store, not
 * browser input. Without 'serviceKey' / 'refresh_id' it acts on the "Open in a table" results page.
 *
 * Every submit and self-method call passes GroupedActionGate with $groupedAction (authorize()); the action should
 * only touch selectedModels(): the checked rows that are among the table's current results.
 */
class AbstractGroupedActionModal extends Modal
{
    use SearchKomponentUtils;

    protected $noHeaderButtons = true;

    // Default: the results page's service. A table opening the modal passes its own.
    protected $serviceKey = SearchResults::SEARCH_ID;

    protected $ids;

    /**
     * Passed to GroupedActionGate (and the searchable's groupedActionPermission()): subclasses name their action.
     * Untyped like the other overridable props: a typed one makes a host's `protected $groupedAction = 'archive'` fatal.
     */
    protected $groupedAction = 'grouped-action';

    protected ?bool $actionAllowed = null;

    public function created()
    {
        if (is_string($serviceKey = $this->prop('serviceKey')) && $serviceKey !== '') {
            $this->serviceKey = $serviceKey;
        }

        $this->setSearchProps();

        // The table's own entity, never the default-results fallback: a lost state (pruned link) would otherwise
        // act on the default entity's rows that happen to share the selected ids.
        $this->searchableInstance = $this->state->getSearchableInstance();

        $ids = static::parseItemIds($this->prop('itemIds'));

        // Only a host opening the modal itself gets here with too many (searchbarGroupedActionModal() refuses them):
        // nothing, never a cut selection.
        $this->ids = static::tooManyItemIds($ids) ? [] : $ids;
    }

    /**
     * The checked ids: a list from the browser (withCheckedItemIds) or the modal's comma-separated prop. Plain
     * non-blank ids only, so a crafted value is never a 500 and an empty selection never reaches whereKey().
     */
    public static function parseItemIds($ids): array
    {
        $ids = is_string($ids) ? explode(',', $ids) : (is_array($ids) ? $ids : []);

        return collect($ids)
            ->filter(fn($id) => is_int($id) || is_string($id))
            ->map(fn($id) => trim((string) $id))
            ->filter(fn($id) => $id !== '')
            ->unique()->values()->all();
    }

    /**
     * Most checked rows one action takes (config searchbar.grouped-actions-max-ids). The ids come from the browser and
     * each one is a row loaded, checked (deletable(), model security) and acted on in one transaction: a crafted list
     * of every id must not become "act on all the matching rows".
     */
    public static function maxItemIds(): int
    {
        return max(1, (int) config('searchbar.grouped-actions-max-ids', 500));
    }

    /** More than maxItemIds(): the selection is refused as a whole, never cut to a part the user can't tell apart. */
    public static function tooManyItemIds(array $ids): bool
    {
        return count($ids) > static::maxItemIds();
    }

    /**
     * Kompo's main gate: every submit and self-method call (AuthorizationGuard::mainGate). The table hides the action
     * too, but a self-method name is portable (encrypted, not bound to a komponent): this is the enforcement.
     */
    public function authorize()
    {
        return parent::authorize() && $this->actionAllowed();
    }

    public function failedAuthorization()
    {
        return __('filter.grouped-action-not-allowed');
    }

    protected function actionAllowed(): bool
    {
        return $this->actionAllowed ??= GroupedActionGate::allows($this->searchableInstance, (string) $this->groupedAction);
    }

    protected function refreshTarget(): string
    {
        return $this->prop('refresh_id') ?: AbstractResultsTable::ID;
    }

    /** The checked rows among the table's current results (the ids come from the browser). */
    protected function selectedModels(): Collection
    {
        // get(), not pluck(): a full-text query orders by its "relevance" alias and binds its select.
        return !$this->searchableInstance || !$this->ids ? collect() : $this->resultsQuery()->whereKey($this->ids)->get();
    }

    /**
     * The results the selection is narrowed to: the state's query on the searchable's baseSearchQuery(), not a host
     * table's own base query (searchbarQuery(Person::where(...))): the modal can't see the table. A modal of such a
     * table returns $this->searchService->getQuery(<that base>) here, as the table's groupedActionsQuery() does; the
     * gate and the model security apply either way, this keeps crafted ids outside the table's rows out.
     */
    protected function resultsQuery()
    {
        return $this->searchService->getQuery();
    }

    public function render()
    {
        return $this->actionAllowed() ? parent::render() : _Modal(
            _ModalBody(
                _Html('filter.grouped-action-not-allowed')->class('text-lg mb-4'),
                _FlexCenter(_LinkOutlined('filter.cancel')->closeModal()),
            )->class($this->bodyWrapperClass),
        );
    }
}
