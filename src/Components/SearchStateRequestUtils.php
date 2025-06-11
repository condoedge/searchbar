<?php

namespace Kompo\Searchbar\Components;

use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\OperatorEnum;
use Kompo\Searchbar\SearchService;

trait SearchStateRequestUtils
{
    protected $serviceKey = SearchService::DEFAULT_KEY;

    public function setRuleOperator($i, $operator)
    {
        $operator = OperatorEnum::from($operator);

        $rule = $this->state->getRules()->get($i);
        $rule->setOperator($operator);

        $this->state->replaceRule($i, $rule);

        stateStore($this->serviceKey)->storeState($this->state);
    }
}