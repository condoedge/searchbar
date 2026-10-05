<?php
namespace Kompo\Searchbar\SearchItems\Rules;

use Kompo\Searchbar\SearchItems\Stores\StateCodec;
use Kompo\Searchbar\SearchService;

class RulesService
{
    const RULES_KEY = 'rules';

    /**
     * @deprecated read stored states with StateCodec::decode(). Unused by the package; removed in a next release.
     *
     * The rules of a v1 store ($store($key): serialize(Rule) strings), read by StateCodec: none of their classes is
     * instantiated (they were unserialized as they came), each is rebuilt through the current filterables of
     * $store('searchableEntity') when it is a searchable class, else of the default results entity. Those that no
     * longer fit are dropped and logged.
     */
    public static function retrieveRulesFromStore($store, $key = self::RULES_KEY, ?SearchService $service = null)
    {
        // The callable read one stored array by key (SearchStore did): a host's may not know this one.
        $entity = rescue(fn() => $store('searchableEntity'), null, false);
        $rules = $store($key);

        $state = StateCodec::decode([
            'searchableEntity' => StateCodec::isSearchableClass($entity) ? $entity : null,
            'search' => null,
            'rules' => $rules instanceof \Traversable || is_array($rules) ? collect($rules)->values()->all() : [],
        ], $service ?? searchService());

        return $state ? $state->getRules()->values() : collect();
    }

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
