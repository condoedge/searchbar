<?php

namespace Kompo\Searchbar\SearchItems\Filterables;

use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\Kompo\RuleForm\ScopeSelectRuleForm;
use Kompo\Searchbar\SearchItems\Rules\ScopeRule;

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
            return [$scope => $i];
        });
    }

    public function getRuleInstance($params)
    {
        return new ScopeRule($params['scope']);
    }

    public function formRow($rule, $index)
    {
        return _FlexEnd(
            _Select()->name('value')->options($this->optionsScopes()->toArray())
                ->selfPost('setRuleValue', ['i' => $index])->withAllFormValues()
                ->refresh('navbar-search')->class('!mb-0')->value($rule->getValue()),
        );
    }
}