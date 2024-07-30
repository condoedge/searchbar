<?php

namespace Kompo\Searchbar\SearchItems\Filterables;

use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumnTypeEnum;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\OperatorEnum;
use Kompo\Searchbar\Components\RuleForm\MultipleColumnTextRuleForm;
use Kompo\Searchbar\SearchItems\Rules\MultipleColumnTextRule;

class FilterableMultipleColumnText extends Filterable
{
    use AcceptFullTextSearch;

    protected $availableMethods = [
        'setColumns',
        'setRuleOperator',
    ];

    protected array $columnsOptions;
    protected $form = MultipleColumnTextRuleForm::class;

    public function __construct(array $columnsOptions)
    {
        $this->columnsOptions = $columnsOptions;
    }

    public function formRow($rule, $index): array
    {
        return [
            $this->getSelectColumnOptions()->class('!mb-0')
                ->name('columns')->default($rule->getColumns())
                ->onChange(fn($e) => $e->selfPost('executeCustomFilterableFunction', ['i' => $index, 'function' => 'setColumns'])->refresh('navbar-search'))
                ->class('!mb-0')
                ->col('col-md-3'),

            _Select()->class('!mb-0')->options(FilterableColumnTypeEnum::TEXT->getOperatorOptionsParsed())
                ->name('operator')->default($rule->getOperator())
                ->selfPost('executeCustomFilterableFunction', ['i' => $index, 'function' => 'setRuleOperator'])->refresh('navbar-search')
                ->class('!mb-0')
                ->col('!p-0 col-md-3'),

            _Input()->name('value')->selfPost('setRuleValue', ['i' => $index])->refresh('navbar-search')->class('!mb-0')
                ->value($rule->getValue())
                ->col('col-md-6'),
        ];
    }

    protected function setRuleOperator($i)
    {
        $this->updateRule($i, function($rule) {
            $rule->setOperator(OperatorEnum::from(request('operator')));

            return $rule;
        });
    }

    public function setColumns($i)
    {
        $this->updateRule($i, function($rule) {
            $rule->setColumns(request('columns'));

            return $rule;
        });
    }

    // GETTERS
    public function getColumnsOptions()
    {
        return $this->columnsOptions;
    }

    public function getSelectColumnOptions()
    {
        return _MultiSelect()->options(collect($this->columnsOptions)->mapWithKeys(fn($column, $i) => [$i => __($column)])->toArray());
    }

    public function getRuleInstance($params)
    {
        return new MultipleColumnTextRule($params['columns'], $params['operator'], $params['value']);
    }
}