<?php

namespace Kompo\Searchbar\SearchItems\Filterables;

use Kompo\Searchbar\Components\RuleForm\AbstractRuleForm;
use Kompo\Searchbar\SearchItems\SearchItem;

abstract class Filterable extends SearchItem
{
    protected $name;
    protected $assignedRule;
    protected $form;

    protected $availableMethods = [];

    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Used to get the name of column or scope
     */
    public function getName()
    {
        return $this->name ?? '';
    }

    /**
     * Used to get the name showed on CustomFiltersModal (type of filter)
     * @return void
     */
    public function getFilterName()
    {
        return $this->getName();
    }

    public function executeCustomMethod($function, $i)
    {
        if (!in_array($function, $this->availableMethods)) {
            return;
        }

        return $this->$function($i);
    }

    protected function updateRule($i, $callback)
    {
        $state = searchService()->getStore()->getState();
        
        $rule = $state->getRules()->get($i);
        $rule = $callback($rule);

        $state->replaceRule($i, $rule);

        stateStore()->storeState($state);
    }

    public function setAssignedRule($rule)
    {
        $this->assignedRule = $rule;

        return $this;
    }

    public function getAssignedRule()
    {
        return $this->assignedRule;
    }

    public final function form($key, $storeKey): AbstractRuleForm
    {
        if(!$this->form || !class_exists($this->form) || !is_subclass_of($this->form, AbstractRuleForm::class)) {
            throw new \Exception('Form not found or not a subclass of AbstractRuleForm. Please set the form property "form" in your Filterable class.');
        }

        return new ($this->form)(['key' => $key, 'storeKey' => $storeKey]);
    }

    abstract public function getRuleInstance($params);

    abstract public function formRow($rule, $index): array;

    public function defaultValueParsed($val)
    {
        return $val;
    }
}