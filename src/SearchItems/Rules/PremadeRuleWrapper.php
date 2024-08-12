<?php

namespace Kompo\Searchbar\SearchItems\Rules;

class PremadeRuleWrapper extends Rule
{
    protected Rule $rule;
    protected string $name;
    protected string $key;
    protected string $description;
    protected bool $removable;
    protected bool $default = false;
    protected bool $inverse = false;

    public function __construct($rule, $key, $name = '', $description = '', $removable = true, $inverse = false, $default = false)
    {
        $this->rule = $rule;
        $this->key = $key;
        $this->name = $name;
        $this->description = $description;
        $this->removable = $removable;
        $this->inverse = $inverse;
        $this->default = $default;
    }

    public function created()
    {
        $this->rule->injectContext($this->searchContextService);
    }

    public function renderContent()
    {
        return _RulePill($this->name);
    }

    public function decorateQuery($query)
    {
        return $this->rule->decorateQuery($query);
    }

    public function toArray()
    {
        return $this->rule->toArray();
    }

    public static function findByKey($searchable, $key)
    {
        return $searchable->getPremadeRules()->first(function ($defaultRule) use ($key) {
            return $defaultRule->getKey() == $key;
        });
    }

    public function getIndexOnRules($rules)
    {
        return $rules->search(function ($rule) {
            if($rule instanceof static) {
                return $rule->getKey() == $this->getKey();
            }

            return false;
        });
    }

    public function isActive()
    {
        $state = $this->searchContextService->getStore()->getState();

        return $this->getIndexOnRules($state->getRules()) !== false;
    }

    public function getToggle()
    {
        $active = $this->isActive();
        $value = $this->isInverse() ? !$active : $active;

        return _Toggle($this->getDescription())->name('toggle' . $this->getKey())->value($value)->selfPost('toggleDefaultRule', ['key' => $this->getKey()])->refresh('navbar-search');
    }

    // GETTERS AND SETTERS
    public function getName()
    {
        return $this->name;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function isRemovable()
    {
        return $this->removable;
    }

    public function isDefault()
    {
        return $this->default;
    }

    public function isInverse()
    {
        return $this->inverse;
    }

    public function getKey()
    {
        return $this->key;
    }

    public function inverse($inverse = true)
    {
        $this->inverse = $inverse;

        return $this;
    }

    public function removable($removable = true)
    {
        $this->removable = $removable;

        return $this;
    }

    public function default($default = true)
    {
        $this->default = $default;

        return $this;
    }

    public function setRuleName($name)
    {
        $this->name = $name;

        return $this;
    }
    
    public function setRuleDescription($description)
    {
        $this->description = $description;

        return $this;
    }
}