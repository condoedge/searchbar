<?php

namespace Kompo\Searchbar\Components\RuleForm;

use Condoedge\Utils\Kompo\Common\Modal;
use Kompo\Searchbar\Components\SearchKomponentUtils;
use Kompo\Searchbar\SearchService;

/** "New filter": pick any field, then its operator and value (the field's own rule form). */
class BaseRuleForm extends Modal
{
    use SearchKomponentUtils;

    protected $_Title = 'filter.add-rule';
    protected $noHeaderButtons = true;

    public $class = 'py-4 px-8 min-w-72 max-w-2xl';
    public $style = 'max-height: 95vh';

    protected $serviceKey = SearchService::DEFAULT_KEY;

    public function created()
    {
        // Set by CustomFiltersModal: the navbar's state, or a table's.
        $this->serviceKey = $this->prop('serviceKey') ?: $this->serviceKey;

        $this->setSearchProps();
    }

    public function body()
    {
        return _Rows(
            _Select('filter.filter')->options(
                collect($this->searchableInstance->filterables())->mapWithKeys(function($col, $key) {
                    return [$key => __($col->getName())];
                })->toArray()
            )->name('key', false)
            ->onChange(fn($e) => $e->selfGet('getRuleForm')->inPanel('rule-details-form'))
            ->overModal('rule-key' . \Str::random(5) . time()),

            _Panel()->id('rule-details-form'),
        );
    }

    public function getRuleForm($key)
    {
        if (!$key) return null;

        // This form's store (searchService() is the default service's, not necessarily this one).
        return $this->searchableInstance->filterable($key)?->form($key, $this->storeKey, $this->serviceKey, $this->prop('refresh_id'));
    }
}
