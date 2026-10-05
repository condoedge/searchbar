<?php

namespace Kompo\Searchbar\Components;

use Kompo\Searchbar\SearchService;

/**
 * The search service key of a komponent. (Its public setRuleOperator() was an unreached, unvalidated duplicate of
 * the filterables' operator change: removed.)
 */
trait SearchStateRequestUtils
{
    protected $serviceKey = SearchService::DEFAULT_KEY;
}
