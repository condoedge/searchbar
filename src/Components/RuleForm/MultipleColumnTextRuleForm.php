<?php

namespace Kompo\Searchbar\Components\RuleForm;

use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumnTypeEnum;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\OperatorEnum;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;

class MultipleColumnTextRuleForm extends AbstractRuleForm
{
    public function constructRuleFromRequest(Filterable $colSpec): FilterableRule
    {
        $value = request('value');
        $columns = request('columns');
        $operator = OperatorEnum::from(request('operator'));

        return $colSpec->getRuleInstance(compact('value', 'operator', 'columns'));
    }

    public function render()
    {
        /**
         * @var \Kompo\Searchbar\SearchItems\Filterables\FilterableMultipleColumnText $colSpec
         */
        $colSpec = $this->searchableInstance->filterable($this->key);

        return _Rows(
            $colSpec->getSelectColumnOptions()->name('columns'),

            _Select()->name('operator')->options(FilterableColumnTypeEnum::TEXT->getOperatorOptionsParsed())
                ->default(FilterableColumnTypeEnum::TEXT->defaultOperator()->value),

            _Input()->name('value'),

            _FlexEnd(
                _SubmitButton('generic.save')->onSuccess(fn($e) => $e->refresh('navbar-search')->refresh('custom-filters-modal')->closeModal()),
            ),
        );
    }
}