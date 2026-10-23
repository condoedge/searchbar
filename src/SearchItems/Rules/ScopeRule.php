<?php

namespace Kompo\Searchbar\SearchItems\Rules;

class ScopeRule extends FilterableRule
{    
    protected $scope;
    protected $params;

    public function __construct($scope, ...$params)
    {
        $this->scope = $scope;
        $this->params = $params;
    }

    public function decorateQuery($query)
    {
        // The scope name is called on the builder: anything but a model's local scope (delete, update, truncate...)
        // would run as-is if a stored rule ever carried it.
        $model = method_exists($query, 'getModel') ? $query->getModel() : null;

        // Local scopes, and builder macros known to only read (soft deletes also register restore(), which writes).
        $readOnlyMacros = config('searchbar.scope-macros', ['withTrashed', 'onlyTrashed', 'withoutTrashed']);

        $isScope = is_string($this->scope) && $model && (
            $model->hasNamedScope($this->scope)
            || (in_array($this->scope, $readOnlyMacros, true) && (
                (method_exists($query, 'hasMacro') && $query->hasMacro($this->scope))
                || \Illuminate\Database\Eloquent\Builder::hasGlobalMacro($this->scope)
            ))
        );

        if (!$isScope) {
            \Log::warning('searchbar.scope_rule_rejected', ['scope' => $this->scope, 'model' => $model ? get_class($model) : null]);

            // Fail closed: premade rules often restrict (forTeam): skipping one would widen the results.
            return $this->scope === null ? $query : $query->whereRaw('1 = 0');
        }

        return $query->{$this->scope}(...$this->params);
    }

    public function toArray()
    {
        return [
            $this->scope,
            ...$this->params,
        ];
    }

    /**
     * The scope (the value of a select-scope filter) and its params (a scope filter's inputs). A scope filter's own
     * scope is the filterable's when read back (FilterableScope::ruleFromData()), never the stored one.
     */
    public function toData(): array
    {
        return ['value' => $this->scope] + ($this->params ? ['params' => array_values((array) $this->params)] : []);
    }

    public function renderContent()
    {
        return _Html($this->getFilterable()?->getName() ?? '');
    }

    public function getValue()
    {
        return $this->scope;
    }

    public function setValue($value)
    {
        $this->scope = $value;
    }

    public function getParams()
    {
        return $this->params;
    }

    public function setParams($params)
    {
        $this->params = $params;
    }
}