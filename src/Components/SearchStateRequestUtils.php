<?php

namespace Kompo\Searchbar\Components;

use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\OperatorEnum;
use Kompo\Searchbar\SearchItems\Rules\PremadeRuleWrapper;
use Kompo\Searchbar\SearchItems\Rules\RulesService;
use Kompo\Searchbar\SearchService;

trait SearchStateRequestUtils
{
    protected $serviceKey = SearchService::DEFAULT_KEY;

    public function closeSearch()
    {
        $this->state->setOpen(false);

        stateStore($this->serviceKey)->storeState($this->state);
    }

    public function openSearch()
    {
        $this->state->setOpen(true);

        stateStore($this->serviceKey)->storeState($this->state);
    }

    public function setSearch($search)
    {
        $this->state->setSearch($search);

        stateStore($this->serviceKey)->storeState($this->state);
    }

    public function getBack()
    {
        $this->state->setSearchableEntity(null);
        $this->state->setRules(collect([]));

        stateStore($this->serviceKey)->storeState($this->state);
    }

    public function selectSearchableEntity($entity)
    {
        $this->state->setSearchableEntity($entity);
        $this->state->setRules(collect($this->state->getSearchableInstance()->getInitialRules())->filter());
        $this->state->setSearch(null);

        stateStore($this->serviceKey)->storeState($this->state);
    }

    public function deleteRule($i)
    {
        $this->state->removeRule($i);

        stateStore($this->serviceKey)->storeState($this->state);
    }

    public function toggleDefaultRule()
    {
        $name = request('key');
        $value = request('toggle' . str_replace('.', '_', $name));

        $rule = PremadeRuleWrapper::findByKey($this->state->getSearchableInstance(), $name);

        $value = $rule->isInverse() ? !$value : $value;

        if($value) {
            $this->state->addRuleAtFirst($rule);
        } else {
            $this->state->removeRule($rule->getIndexOnRules($this->state->getRules()));
        }
    }

    public function setRuleOperator($i, $operator)
    {
        $operator = OperatorEnum::from($operator);
        
        $rule = $this->state->getRules()->get($i);
        $rule->setOperator($operator);

        $this->state->replaceRule($i, $rule);

        stateStore($this->serviceKey)->storeState($this->state);
    }

    public function setRuleValue($i)
    {
        $value = request('value');
        $rule = $this->state->getRules()->get($i);
        $rule->setValue($value);

        $this->state->replaceRule($i, $rule);

        stateStore($this->serviceKey)->storeState($this->state);
    }

    public function setRuleParam($i)
    {
        $params = request('param');
        $rule = $this->state->getRules()->get($i);
        $rule->setParams($params);

        $this->state->replaceRule($i, $rule);

        stateStore($this->serviceKey)->storeState($this->state);
    }

    public function addRule()
    {
        $this->state->addRule(RulesService::retrieveRuleFromRequest('rule'));

        stateStore($this->serviceKey)->storeState($this->state);
    }

    public function cleanSearch()
    {
        $this->state->setSearch(null);

        stateStore($this->serviceKey)->storeState($this->state);
    }
}