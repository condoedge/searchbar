<?php

namespace Kompo\Searchbar\Components\RuleForm;

use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\OperatorEnum;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;

class ColumnRuleForm extends AbstractRuleForm
{
    public function constructRuleFromRequest(Filterable $colSpec): FilterableRule
    {
        $operator = OperatorEnum::from(request('operator'));
        $value = request('value');

        return $colSpec->getRuleInstance([
            'operator' => $operator,
            'value' => $value,
        ]);
    }

    public function render()
    {
        /**
         * @var \Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumn $colSpec
         */
        $colSpec = $this->searchableInstance->filterable($this->key);

        return _Rows(
            _Html($colSpec->getName()),

            _Select()->name('operator')->options($colSpec->getInputType()->getOperatorOptionsParsed())
                ->selfGet('setValueInput')->inPanel('input-panel')->default($colSpec->getInputType()->defaultOperator()),

            _Panel(
                $colSpec->getInput(),
            )->id('input-panel'),

            _FlexEnd(
                _SubmitButton('generic.save')->onSuccess(fn($e) => $e->refresh('navbar-search')->refresh('custom-filters-modal')->closeModal()),
            ),
        );
    }

    public function setValueInput($operator)
    {
        /**
         * @var \Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumn $colSpec
         */
        $colSpec = $this->searchableInstance->filterable($this->key);
        $operator = OperatorEnum::from($operator);

        return $colSpec->getInput($operator);
    }
}