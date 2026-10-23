<?php
namespace Kompo\Searchbar\SearchItems\Rules;

class RulesService
{
    /** A rule for a request parameter (chip links): signed, see searchbarSign(). */
    public static function encodeRule(Rule $rule): string
    {
        return searchbarSign(base64_encode(serialize($rule)));
    }

    public static function retrieveRuleFromRequest($key)
    {
        $payload = searchbarVerify(request($key));

        if ($payload === null) {
            return null;
        }

        $rule = unserialize(base64_decode($payload));

        return $rule instanceof Rule ? $rule : null;
    }
}
