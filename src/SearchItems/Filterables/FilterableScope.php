<?php

namespace Kompo\Searchbar\SearchItems\Filterables;

use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\Kompo\RuleForm\ScopeRuleForm;
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

    public function formRow($rule, $index)
    {
        return _Flex(
            $this->getInputs()->map(fn($input, $i) => $input
                ->default($rule->getParams()[$i])
                ->selfPost('setRuleParam', ['i' => $index])->withAllFormValues()->refresh('navbar-search')
            )
        )->class('gap-3');
    }

    public function getScope()
    {
        return $this->scope;
    }
}