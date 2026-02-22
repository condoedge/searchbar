<?php

namespace Kompo\Searchbar\Http\Controllers;

use Illuminate\Routing\Controller;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\OperatorEnum;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;
use Kompo\Searchbar\SearchItems\Rules\PremadeRuleWrapper;
use Kompo\Searchbar\SearchItems\Rules\RulesService;
use Kompo\Searchbar\SearchService;

class SearchStateController extends Controller
{

    protected $state;
    protected $serviceKey;
    protected $searchableInstance;
    protected $storeKey;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->storeKey = request('storeKey');
            $this->serviceKey = $this->getServiceKey();

            searchService($this->serviceKey)->setStoreKey($this->storeKey);
            $this->state = stateStore($this->serviceKey)->getState();
            $this->searchableInstance = $this->state->getSearchableInstance();

            return $next($request);
        });
    }

    protected function getServiceKey()
    {
        return request('serviceKey') ?? SearchService::DEFAULT_KEY;
    }

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

    public function setSearch()
    {
        $search = request('search');
        $this->state->setSearch($search);

        stateStore($this->serviceKey)->storeState($this->state);
    }

    public function getBack()
    {
        $this->state->setSearchableEntity(null);
        $this->state->setRules(collect([]));

        stateStore($this->serviceKey)->storeState($this->state);
    }

    public function selectSearchableEntity()
    {
        $entity = request('searchableEntity');

        $this->state->setSearchableEntity($entity);
        $this->state->setRules(collect($this->state->getSearchableInstance()->getInitialRules())->filter());
        $this->state->setSearch(null);

        stateStore($this->serviceKey)->storeState($this->state);
    }

    public function deleteRule()
    {
        $i = request('i');
        $this->state->removeRule($i);

        stateStore($this->serviceKey)->storeState($this->state);
    }

    public function toggleDefaultRule()
    {
        $name = request('key');
        $value = request('toggle' . str_replace('.', '_', $name));

        $rule = PremadeRuleWrapper::findByKey($this->state->getSearchableInstance(), $name);

        $value = $rule->isInverse() ? !$value : $value;

        if ($value) {
            $this->state->addRuleAtFirst($rule);
        } else {
            $this->state->removeRule($rule->getIndexOnRules($this->state->getRules()));
        }
    }

    public function setRuleValue()
    {
        $i = request('i');
        $value = request('value');

        if ($value) {
            $rule = $this->state->getRules()->get($i);
            $rule->setValue($value);

            $this->state->replaceRule($i, $rule);
        } else {
            // If the value is empty, we remove the rule
            $this->state->removeRule($i);
        }

        stateStore($this->serviceKey)->storeState($this->state);
    }

    public function setRuleParam()
    {
        $i = request('i');
        $params = request('param');
        $rule = $this->state->getRules()->get($i);
        $rule->setParams($params);

        $this->state->replaceRule($i, $rule);

        stateStore($this->serviceKey)->storeState($this->state);
    }

    public function addRule()
    {
        $rule = RulesService::retrieveRuleFromRequest('rule');

        $this->state->addRule($rule );

        stateStore($this->serviceKey)->storeState($this->state);
    }

    public function toggleSectionRule()
    {
        $rule = RulesService::retrieveRuleFromRequest('rule');

        $existingIndex = $this->state->getFilterableRules()->search(function ($r) use ($rule) {
            return $r instanceof FilterableRule
                && $r->getKeyReference() === $rule->getKeyReference()
                && $r->getValue() == $rule->getValue();
        });

        if ($existingIndex !== false) {
            $this->state->removeRule($existingIndex);
        } else {
            $this->state->addRule($rule);
        }

        stateStore($this->serviceKey)->storeState($this->state);
    }

    public function cleanSearch()
    {
        $this->state->setSearch(null);

        stateStore($this->serviceKey)->storeState($this->state);
    }

    public function setInlineFilterValue()
    {
        $key = request('key');
        $value = request('inline_' . $key);

        $ruleIndex = $this->state->getFilterableRules()->search(
            fn($rule) => $rule->getKeyReference() === $key
        );

        if ($ruleIndex === false) return;

        $rule = $this->state->getRules()->get($ruleIndex);

        if ($value !== null && $value !== '' && $value !== [null, null]) {
            $rule->setValue($value);
            $this->state->replaceRule($ruleIndex, $rule);
        } else {
            $this->state->removeRule($ruleIndex);
        }

        stateStore($this->serviceKey)->storeState($this->state);
    }

    public function executeCustomFilterableFunction()
    {
        $i = request('i');
        $function = request('function');
        $rule = $this->state->getRules()->get($i);

        return $rule->getFilterable($this->state->getSearchableInstance())->executeCustomMethod($function, $i);
    }
}
