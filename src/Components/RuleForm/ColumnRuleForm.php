<?php

namespace Kompo\Searchbar\Components\RuleForm;

use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\OperatorEnum;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;

class ColumnRuleForm extends AbstractRuleForm
{
    public function constructRuleFromRequest(Filterable $colSpec): FilterableRule
    {
        $operator = OperatorEnum::tryFrom((int) request('operator'));
        $operator = in_array($operator, $colSpec->getInputType()->operatorOptions(), true) ? $operator : $colSpec->getInputType()->defaultOperator();

        return $colSpec->getRuleInstance([
            'operator' => $operator,
            // In the operator's shape: a scalar under BETWEEN, or an array under CONTAINS, broke the query.
            'value' => $colSpec->getInputType()->normalizeValue(request('value'), $operator),
        ]);
    }

    public function render()
    {
        /**
         * @var \Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumn $colSpec
         */
        $colSpec = $this->searchableInstance->filterable($this->key);

        return _Rows(
            _Select()->name('operator')->options($colSpec->getInputType()->getOperatorOptionsParsed())
                ->default($colSpec->getInputType()->defaultOperator())
                ->onChange(fn($e) => $e
                    ->selfGet('setValueInput')->inPanel('input-panel')
                )
                ->overModal('operator' . \Str::random(5) . time()), 

            _Panel(
                $colSpec->getInput(),
            )->id('input-panel'),

            _FlexEnd(
                _SubmitButton('generic.save')->onSuccess(fn($e) => $e->refresh($this->refreshAfterSave())->closeModal()),
            ),
        );
    }

    public function setValueInput($operator)
    {
        /**
         * @var \Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumn $colSpec
         */
        $colSpec = $this->searchableInstance->filterable($this->key);
        // A crafted operator (999, "x") failed with a 500: only the field's own operators.
        $operator = OperatorEnum::tryFrom((int) $operator);
        $operator = in_array($operator, $colSpec->getInputType()->operatorOptions(), true) ? $operator : $colSpec->getInputType()->defaultOperator();

        return $colSpec->getInput($operator);
    }
}