<?php

namespace Kompo\Searchbar\SearchItems\Filterables;

use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\Components\RuleForm\ScopeRuleForm;
use Kompo\Searchbar\SearchItems\Rules\ScopeRule;

class FilterableScope extends Filterable
{
    protected string $scope;
    protected array $inputTypes;
    protected $form = ScopeRuleForm::class;

    public function __construct(string $scope, array $inputTypes)
    {
        $this->scope = $scope;
        $this->inputTypes = $inputTypes;
    }

    public function getRuleInstance($params)
    {
        $values = $params['value'] ?? [];
        $values = !is_array($values) ? [$values] : $values;

        return new ScopeRule($this->getScope(), ...$values);
    }

    public function getInputs()
    {
        return collect($this->inputTypes)->map(function ($inputType, $i) {
            return $inputType->input()->name('param[' . $i . ']')->class('!mb-0');
        });
    }

    public function formRow($rule, $index): array
    {
        return [
            _Html($this->getFilterName())->col('!pr-0 col-md-3'),
            _Html()->col('col-md-3'),
            _Flex(
                $this->getInputs()->map(fn($input, $i) => $input
                ->default($rule->getParams()[$i])
                ->selfPost('setRuleParam', ['i' => $index])->withAllFormValues()->refresh('navbar-search')
                )
            )->col('col-md-6 flex-wrap'),
        ];
    }

    public function getScope()
    {
        return $this->scope;
    }
}