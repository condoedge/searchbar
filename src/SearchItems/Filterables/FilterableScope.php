<?php

namespace Kompo\Searchbar\SearchItems\Filterables;

use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\Components\RuleForm\ScopeRuleForm;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;
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

    /** The scope is this filter's (never a stored name: it is called on the query), the params as its inputs posted them. */
    public function ruleFromData(array $data): FilterableRule
    {
        $params = is_array($data['params'] ?? null) ? $data['params'] : [];

        return $this->getRuleInstance(['value' => collect($params)->filter(fn($param) => is_null($param) || is_scalar($param))->values()->all()]);
    }

    public function getInputs()
    {
        return collect($this->inputTypes)->map(function ($inputType, $i) {
            return $inputType->input()->name('param[' . $i . ']')->class('!mb-0');
        });
    }

    /** Fields named by rule id (param_{id}[0]): see FilterableColumn::formRow(). */
    public function formRow($rule, $ruleId): array
    {
        return [
            _Html($this->getFilterName())->class('text-sm font-semibold text-greenmain')->col('!pr-0 col-md-3'),
            _Html()->col('col-md-3'),
            _Flex(
                $this->getInputs()->map(fn($input, $i) => $input
                ->name('param_' . $ruleId . '[' . $i . ']')
                ->default($rule->getParams()[$i] ?? null)
                ->post('searchstate.set-rule-param', $this->ruleRowParams($ruleId))->withAllFormValues()->refresh($this->getContext()->refreshTargets())
                )
            )->col('col-md-6 flex-wrap'),
        ];
    }

    public function getScope()
    {
        return $this->scope;
    }
}