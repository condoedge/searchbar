<?php

namespace Kompo\Searchbar\Components\RuleForm;

use Kompo\Auth\Common\Modal;
use Kompo\Searchbar\Components\SearchKomponentUtils;

class BaseRuleForm extends Modal
{
    use SearchKomponentUtils;

    protected $_Title = 'filter.add-rule';
    protected $noHeaderButtons = true;
    
    public $class = 'py-4 px-8 min-w-72 max-w-2xl';
    public $style = 'max-height: 95vh';

    public function body()
    {
        return _Rows(
            _Select()->options(
                collect($this->searchableInstance->filterables())->mapWithKeys(function($col, $key) {
                    return [$key => __($col->getName())];
                })->toArray()
            )->name('key', false)->selfGet('getRuleForm')->inPanel('rule-details-form')
            ->overModal('rule-key'),

            _Panel()->id('rule-details-form'),
        );
    }

    public function getRuleForm($key)
    {
        return $this->searchableInstance->filterable($key)->form($key, searchService()->getStoreKey());
    }
}