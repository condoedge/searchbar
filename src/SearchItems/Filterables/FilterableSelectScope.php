<?php

namespace Kompo\Searchbar\SearchItems\Filterables;

use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\Components\RuleForm\ScopeSelectRuleForm;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;
use Kompo\Searchbar\SearchItems\Rules\ScopeRule;
use Kompo\Searchbar\SearchItems\Rules\UnusableRuleData;

class FilterableSelectScope extends Filterable
{
    protected array $options;
    protected $form = ScopeSelectRuleForm::class;

    public function __construct(array $options)
    {
        $this->options = $options;
    }

    public function getName()
    {
        if($this->assignedRule) {
            return collect($this->options)->search(fn($o) => $this->assignedRule->getValue() == $o);
        }

        return $this->name;
    }

    public function getFilterName()
    {
        return $this->name;
    }

    public function optionsScopes()
    {
        return collect($this->options)->mapWithKeys(function ($scope, $i) {
            return [$scope => __($i)];
        });
    }

    public function getRuleInstance($params)
    {
        return new ScopeRule($this->isAllowedScope($params['scope'] ?? null) ? $params['scope'] : null);
    }

    /**
     * One of the declared scopes (one removed since, like SISC's hasYoungTeamOccupation, is unusable), with the scalar
     * params it had (searchstate/set-rule-param).
     */
    public function ruleFromData(array $data): FilterableRule
    {
        if (!$this->isAllowedScope($data['value'] ?? null)) {
            throw new UnusableRuleData('invalid_scope', UnusableRuleData::describe($data['value'] ?? null));
        }

        $rule = $this->getRuleInstance(['scope' => $data['value']]);
        $rule->setParams(collect(is_array($data['params'] ?? null) ? $data['params'] : [])->filter(fn($param) => is_null($param) || is_scalar($param))->values()->all());

        return $rule;
    }

    /** The scope is called on the query: only the declared ones. */
    public function isAllowedScope($scope): bool
    {
        return is_string($scope) && in_array($scope, $this->options, true);
    }

    /** Field named by rule id: see FilterableColumn::formRow(). */
    public function formRow($rule, $ruleId): array
    {
        return [
            _Html($this->getFilterName())->class('text-sm font-semibold text-greenmain')->col('!pr-0 col-md-3'),
            _Html()->col('col-md-3'),
            _Select()->name('value_' . $ruleId)->options($this->optionsScopes()->toArray())
                ->post('searchstate.set-rule-value', $this->ruleRowParams($ruleId))->withAllFormValues()
                ->refresh($this->getContext()->refreshTargets())->class('!mb-0')->value($rule->getValue())
                ->col('col-md-6'),
        ];
    }

    // INLINE EDITION (rule pills): pick another of the scopes
    public function supportsInlineEdit(): bool
    {
        return true;
    }

    public function inlineEnterApplies(): bool
    {
        return false;
    }

    public function getInlineEditor($name, $value = null, $operator = null, $onApply = null)
    {
        // Same select as the column pills: not clipped by the pill row, above the search panel.
        return \Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumnTypeEnum::SELECT
            ->inlineSelect(_Select()->default($value), $name, $this->optionsScopes()->toArray())
            ->when($onApply, fn($el) => $el->onChange($onApply));
    }

    public function normalizeInlineValue($value, $operator = null)
    {
        return $this->isAllowedScope($value) ? $value : null;
    }
}
