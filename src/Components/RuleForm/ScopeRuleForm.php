<?php

namespace Kompo\Searchbar\Components\RuleForm;

use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;

class ScopeRuleForm extends AbstractRuleForm
{
    public function constructRuleFromRequest(Filterable $colSpec): FilterableRule
    {
        $value = request('value');

        return $colSpec->getRuleInstance(compact('value'));
    }

    public function render()
    {
        /**
         * @var \Kompo\Searchbar\SearchItems\Filterables\FilterableScope $colSpec
         */
        $colSpec = $this->searchableInstance->filterable($this->key);

        return _Rows(
            _Html($colSpec->getName()),

            _Rows(
                $colSpec->getInputs()
            ),

            _FlexEnd(
                _SubmitButton('generic.save')->onSuccess(fn($e) => $e->refresh('navbar-search')->refresh('custom-filters-modal')->closeModal()),
            ),
        );
    }
}