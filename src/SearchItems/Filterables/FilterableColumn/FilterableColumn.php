<?php

namespace Kompo\Searchbar\SearchItems\Filterables\FilterableColumn;

use Kompo\Searchbar\SearchItems\Filterables\AcceptFullTextSearch;
use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\EntityType\EntityType;
use Kompo\Searchbar\Components\RuleForm\ColumnRuleForm;

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
            _Html($this->getFilterName())->col('!pr-0 col-md-3'),
            _Select()->class('!mb-0')->options($this->getInputType()->getOperatorOptionsParsed())
            ->name('operator')->default($rule->getOperator())
            ->onChange(fn($e) => $e->post('searchstate.execute-custom-filterable-function', ['i' => $index, 'function' => 'setRuleOperator'])->withAllFormValues()->refresh('navbar-search') &&
                $e->post('searchstate.execute-custom-filterable-function', ['i' => $index, 'function' =>'setValueInput'])->withAllFormValues()
                ->inPanel('input-panel' . $index)
            )
            ->overModal('operator' . \Str::random(5) . time())
            ->class('!mb-0')->col('!p-0 col-md-3'),

            _Panel(
                $this->getInput($rule->getOperator())
                    ->onChange(fn($e) => $e->post('searchstate.set-rule-value', ['i' => $index])->withAllFormValues()
                    ->refresh('navbar-search'))
                    ->class('!mb-0')->value($rule->getValue()),
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

        $colSpec = $rule->getFilterable();
        $operator = OperatorEnum::from(request('operator'));

        if ($colSpec->getInput($previousOperator)::class != $colSpec->getInput($operator)::class) {
            $value = null;
        } 

        return $colSpec->getInput($operator)->post('searchstate.set-rule-value', ['i' => $i])->withAllFormValues()->refresh('navbar-search')->class('!mb-0')
            ->when($value, fn($el) => $el->value($value));
    }

    public function defaultValueParsed($val)
    {
        $entityType = $this->getEntityType();

        if($entityType && $entityType->hasAllowAllOption() && is_array($val) && in_array('all', $val)) {
            $values = $entityType->optionsWithLabels()->values();

            return array_slice($values->toArray(), 1);
        }

        return $val;
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

    public function getInlineInput($name, $onEnter = null, $defaultValue = null)
    {
        $search = $defaultValue ?? searchService()->getStore()->getState()->getSearch();

        return $this->getInputType()->inlineInput($name, $onEnter, $search);
    }
}