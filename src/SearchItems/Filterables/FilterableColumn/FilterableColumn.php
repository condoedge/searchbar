<?php

namespace Kompo\Searchbar\SearchItems\Filterables\FilterableColumn;

use Kompo\Searchbar\SearchItems\Filterables\AcceptFullTextSearch;
use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\EntityType\EntityType;
use Kompo\Searchbar\Kompo\RuleForm\ColumnRuleForm;

class FilterableColumn extends Filterable
{
    use AcceptFullTextSearch;

    protected $availableMethods = [
        'setRuleOperator',
        'setValueInput',
    ];

    protected string $column;
    protected FilterableColumnTypeEnum $inputType;
    protected ?EntityType $entityType;
    protected $form = ColumnRuleForm::class;

    public function __construct(string $column, FilterableColumnTypeEnum $inputType, ?EntityType $entityType = null)
    {
        $this->column = $column;
        $this->inputType = $inputType;
        $this->entityType = $entityType;
    }

    public function formRow($rule, $index): array
    {
        return [
            _Html($this->getName())->col('!pr-0 col-md-3'),
            _Select()->class('!mb-0')->options($this->getInputType()->getOperatorOptionsParsed())
            ->name('operator')->default($rule->getOperator())
            ->onChange(fn($e) => $e->selfPost('executeCustomFilterableFunction', ['i' => $index, 'function' => 'setRuleOperator'])->refresh('navbar-search') &&
                $e->selfGet('executeCustomFilterableFunction', ['i' => $index, 'function' =>'setValueInput'])
                ->inPanel('input-panel' . $index)
            )
            ->class('!mb-0')->col('!p-0 col-md-3'),

            _Panel(
                $this->getInput($rule->getOperator())
                    ->selfPost('setRuleValue', ['i' => $index])
                    ->refresh('navbar-search')->class('!mb-0')->value($rule->getValue()),
            )->id('input-panel' . $index)->col('col-md-6'),
        ];
    }

    protected function setRuleOperator($i)
    {
        $this->updateRule($i, function($rule) {
            $rule->setOperator(OperatorEnum::from(request('operator')));

            return $rule;
        });
    }

    protected function setValueInput($i)
    {
        $state = searchService()->getStore()->getState();

        $rule = $state->getRules()->get($i);
        $value = $rule->getValue();
        $previousOperator = $rule->getOperator();

        $colSpec =$rule->getFilterable($state->getSearchableInstance());
        $operator = OperatorEnum::from(request('operator'));

        if ($colSpec->getInput($previousOperator)::class != $colSpec->getInput($operator)::class) {
            $value = null;
        } 

        return $colSpec->getInput($operator)->selfPost('setRuleValue', ['i' => $i])->refresh('navbar-search')->class('!mb-0')
            ->when($value, fn($el) => $el->value($value));
    }

    // GETTERS
    public function getColumn()
    {
        return $this->column;
    }

    public function getInputType()
    {
        return $this->inputType;
    }

    public function getEntityType()
    {
        return $this->entityType?->injectContext($this->searchContextService);
    }

    public function getRuleInstance($params)
    {
        return $this->getInputType()->getRuleInstance(array_merge([
            'column' => $this->getColumn(),
            'operator' => $this->getInputType()->defaultOperator(),
        ], $params));
    }

    public function getInput($operator = null)
    {
        return $this->getInputType()?->input($this->getEntityType()?->optionsWithLabels() ?: [], $operator ?? $this->getInputType()->defaultOperator())->name('value');
    }
}