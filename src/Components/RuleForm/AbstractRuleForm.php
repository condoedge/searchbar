<?php

namespace Kompo\Searchbar\Components\RuleForm;

use Condoedge\Utils\Kompo\Common\Modal;
use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\Components\SearchbarIds;
use Kompo\Searchbar\Components\SearchKomponentUtils;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;
use Kompo\Searchbar\SearchService;

abstract class AbstractRuleForm extends Modal
{
    use SearchKomponentUtils;

    protected $_Title = 'filter.add-rule';
    protected $key;

    protected $serviceKey = SearchService::DEFAULT_KEY;

    public function created()
    {
        // Set by BaseRuleForm (Filterable::form): the navbar's state, or a table's.
        $this->serviceKey = $this->prop('serviceKey') ?: $this->serviceKey;

        $this->setSearchProps();
        // The table the custom filters modal was opened by, else the navbar (refreshTargets()).
        $this->searchService->setRefreshTarget($this->prop('refresh_id'));

        $this->key = $this->prop('key');
    }

    /** @deprecated refreshAfterSave(): the navbar's id refreshed the whole navbar. */
    protected function refreshTarget(): string
    {
        return $this->prop('refresh_id') ?: SearchbarIds::NAVBAR;
    }

    /**
     * What saving refreshes, in one request: the komponents showing the state (the table, or the navbar's pills,
     * filters and results) and the custom filters modal (its new row).
     */
    protected function refreshAfterSave(): array
    {
        return array_merge($this->searchService->refreshTargets(), [SearchbarIds::CUSTOM_FILTERS]);
    }

    public function handle()
    {
        $colSpec = $this->searchableInstance->filterable($this->key);
        $rule = ($this->constructRuleFromRequest($colSpec))->setKeyReference($this->key);
        $entity = get_class($this->searchableInstance);

        // This form's own store (stateStore() is the default service's), added to the state as it is now (another
        // request may have changed it since this form was opened). A copy per run (mutate() may run it twice).
        $this->state = $this->searchService->getStore()->mutate(function ($state) use ($rule, $entity) {
            // Another tab switched the entity meanwhile: this filter isn't one of its own.
            $searchable = $state->getSearchableInstanceForResultsPanel();

            if (!$searchable || get_class($searchable) !== $entity) {
                return false;
            }

            $state->addRule(clone $rule);
        });
    }

    abstract function constructRuleFromRequest(Filterable $colSpec): FilterableRule;

    public function footer()
    {
        return null;
    }
}
