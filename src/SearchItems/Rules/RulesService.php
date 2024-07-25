<?php
namespace Kompo\Searchbar\SearchItems\Rules;

class RulesService
{
    const RULES_KEY = 'rules';

    public static function retrieveRulesFromStore($store, $key = self::RULES_KEY) 
    {
        return collect($store($key))->map(function($rule) {
            return unserialize($rule);
        });
    }

    public static function retrieveRuleFromRequest($key)
    {
        return unserialize(request($key));
    }
}