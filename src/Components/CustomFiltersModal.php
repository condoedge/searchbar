<?php

namespace Kompo\Searchbar\Components;

use Condoedge\Utils\Kompo\Common\Modal;
use Kompo\Core\KompoAction;
use Kompo\Searchbar\Components\RuleForm\BaseRuleForm;
use Kompo\Searchbar\SearchService;

/**
 * Every filter as a row (field, operator, value of the field's type), default-rule toggles, and "New filter" for
 * any field. Opened by the navbar search panel, or by a table (HasSearchbarFilters::getSearchbarCustomFiltersModal):
 * then it edits the table's state (serviceKey prop), refreshes the table (refresh_id prop) and keeps its entity.
 */
class CustomFiltersModal extends Modal
{
    use SearchStateRequestUtils;
    use SearchKomponentUtils;

    public $id = SearchbarIds::CUSTOM_FILTERS;
    protected $_Title = 'filter.filters';
    public $class = 'max-w-2xl w-screen overflow-y-auto mini-scroll';
    public $style = 'max-height: 95vh';

    public function created()
    {
        // Props set by the server when the modal is built (never read from a URL: modals aren't routes).
        $this->serviceKey = $this->prop('serviceKey') ?: $this->serviceKey;

        $this->setSearchProps();

        // What the rows, toggles and rule forms refresh after a change.
        $this->searchService->setRefreshTarget($this->prop('refresh_id'));
    }

    /** @deprecated refreshAfter(): the navbar's id refreshed the whole navbar. */
    protected function refreshTarget(): string
    {
        return $this->searchService->getRefreshTarget();
    }

    /**
     * What a change made here refreshes, in one request: this modal (its rows follow the state) and the komponents
     * showing the state (a table, or the navbar parts the change touches).
     */
    protected function refreshAfter(string $change = SearchService::CHANGE_RULES): array
    {
        return array_merge([SearchbarIds::CUSTOM_FILTERS], $this->searchService->refreshTargets($change));
    }

    /** Opened by a table: bound to its entity (no "Search in"), reset to its default rules. */
    protected function isForTable(): bool
    {
        return (bool) $this->prop('refresh_id');
    }

    public function headerButtons()
	{
        return _FlexEnd(
            // A table keeps its entity (back to its default rules); the navbar goes back to every entity.
            _ButtonOutlined('filter.reset-filter')->post($this->isForTable() ? 'searchstate.reset-rules' : 'searchstate.get-back', $this->searchService->stateParams())
                ->withAllFormValues()->refresh($this->refreshAfter($this->isForTable() ? SearchService::CHANGE_RULES : SearchService::CHANGE_ENTITY)),
            _Button('filter.new-rule')->selfGet('addRuleModal')->inModal(),
        )->class('gap-4');
	}

    public function body()
    {
        // A relation select of a row searching its options: Kompo boots the modal just to call
        // searchbarRelationOptions(); the rows (label queries, the selects' size check) ran on every keystroke.
        if (KompoAction::is('search-options')) {
            return _Rows();
        }

        $typeInstance = $this->state->getSearchableInstance();

        return _Rows(
            _Hidden()->name('serviceKey')->default($this->serviceKey),
            _Hidden()->name('storeKey')->default($this->storeKey),
            _Rows(
                _Rows(
                    collect($typeInstance?->getPremadeRules())->map(fn($r) => _FlexEnd(
                       $r->getToggle()->class('[&>.vlFormLabel]:w-max'),
                    ))
                ),
                $this->isForTable() ? null : _Rows(
                    $this->rowRule(
                        fn($deleteButton) => $deleteButton->post('searchstate.get-back', $this->searchService->stateParams())->withAllFormValues()
                            ->refresh($this->refreshAfter(SearchService::CHANGE_ENTITY)),
                        _Html('filter.search-in')->col('!pr-0 col-md-3'),
                        _Html()->col('col-md-3'),
                        _Select()->name('searchableEntity')->options(searchService()->getSearchables()->mapWithKeys(fn($searchable) =>
                            [$searchable => $searchable::searchableName()]
                        ))
                            ->default($this->state->getSearchableEntity())
                            // The modal too: its rows and toggles belonged to the previous entity.
                            ->onChange(fn($e) => $e->post('searchstate.select-entity', $this->searchService->stateParams())->withAllFormValues()
                                ->refresh($this->refreshAfter(SearchService::CHANGE_ENTITY)))
                            ->overModal('search-in' . \Str::random(5) . time())
                            ->class('!mb-0 w-full')
                            ->col('col-md-6'),
                    ),
                )->class('mb-4'),
                $this->ruleRows($typeInstance),
            ),
        );
    }

    /**
     * A row per filter, addressed by its rule's id (fields value_{id}, operator_{id}...; requests ?ruleId with the
     * filter key and the state keys): positions shifted when rules changed elsewhere, and a row edited another rule.
     */
    protected function ruleRows($typeInstance)
    {
        $rows = $this->state->getFilterableRules()->map(function($r) use ($typeInstance) {
            // A rule whose filter the entity no longer declares has no row (it made the modal crash).
            $colInfo = rescue(fn() => $r->getFilterable($typeInstance), null, false);

            return !$colInfo ? null : $this->rowRule(function($deleteButton) use ($r) {
                return $deleteButton->post('searchstate.delete-rule', $this->searchService->stateParams(['ruleId' => $r->getId(), 'key' => $r->getKeyReference()]))
                    ->withAllFormValues()->refresh($this->refreshAfter());
            }, ...$colInfo->formRow($r, $r->getId()));
        })->filter();

        // Empty: what a filter is, not only "no filters" (its title keeps the existing key: hosts may override it).
        return $rows->isEmpty()
            ? _Rows(
                _Sax('filter', 28)->class('text-greenmain opacity-60 mb-2'),
                _Html('filter.no-custom-rules')->class('font-semibold text-greenmain'),
                _Html('filter.no-custom-rules-hint')->class('text-sm text-gray-500'),
            )->class('searchbar-custom-filters-empty items-center text-center bg-level5 bg-opacity-40 rounded-2xl p-6')
            : _Rows($rows)->class('gap-y-4');
    }

    protected function rowRule($deleteButtonCallback, ...$inputs)
    {
        return _Flex(
            _Columns(
                ...$inputs,
            )->class('items-center w-full'),

            $deleteButtonCallback(_Link()->icon(_Sax('trash', 18))->class('text-gray-500 hover:text-danger shrink-0'))
        )->class('gap-3 items-center bg-level4 bg-opacity-40 rounded-xl p-3');
    }

    public function footer()
    {
        return null;
    }

    public function addRuleModal()
    {
        return $this->instanciateSearchKomponent(BaseRuleForm::class, [
            'serviceKey' => $this->serviceKey,
            'refresh_id' => $this->prop('refresh_id'),
        ]);
    }
}
