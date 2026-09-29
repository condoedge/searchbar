<?php

namespace Kompo\Searchbar\Components\RuleForm;

use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;

class ScopeRuleForm extends AbstractRuleForm
{
    public function constructRuleFromRequest(Filterable $colSpec): FilterableRule
    {
        // The inputs are named param[i] (FilterableScope::getInputs): request('value') dropped every parameter.
        $value = collect(request('param', []))->filter(fn($param) => is_null($param) || is_scalar($param))->values()->all();

        return $colSpec->getRuleInstance(compact('value'));
    }

    public function render()
    {
        /**
         * @var \Kompo\Searchbar\SearchItems\Filterables\FilterableScope $colSpec
         */
        $colSpec = $this->searchableInstance->filterable($this->key);

        return _Rows(
            _Rows(
                $colSpec->getInputs()
            ),

            _FlexEnd(
                _SubmitButton('generic.save')->onSuccess(fn($e) => $e->refresh($this->refreshAfterSave())->closeModal()),
            ),
        );
    }
}