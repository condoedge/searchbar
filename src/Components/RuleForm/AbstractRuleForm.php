<?php

namespace Kompo\Searchbar\Components\RuleForm;

use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\Components\SearchKomponentUtils
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;
use Kompo\Auth\Common\Modal;

abstract class AbstractRuleForm extends Modal
{
    use SearchKomponentUtils;

    protected $_Title = 'translate.add-rule';
    protected $key;
    
    public function created()
    {
        $this->setSearchProps();

        $this->key = $this->prop('key');
    }

    public function handle()
    {
        $colSpec = $this->searchableInstance->filterable($this->key);

        $this->state->addRule(
            ($this->constructRuleFromRequest($colSpec))->setKeyReference($this->key),
        );

        stateStore()->storeState($this->state);
    }

    abstract function constructRuleFromRequest(Filterable $colSpec): FilterableRule;

    public function footer()
    {
        return null;
    }
}