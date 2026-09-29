<?php

namespace Kompo\Searchbar\SearchItems\Stores;

use Kompo\Searchbar\SearchItems\Rules\PremadeRuleWrapper;
use Kompo\Searchbar\SearchItems\Rules\Rule;

/**
 * Reads a state stored before format v2 (StateCodec) as v2 plain data, without instantiating any class of it:
 * - a v1 array (rows and links written before: ['searchableEntity', 'search', 'rules' => [serialize(Rule)...]], plus
 *   'sort' for working states, 'filtersCount' / 'filters' for recent searches), its rules unserialized with
 *   allowed_classes=false and read from their properties;
 * - a SearchState object (the session held the object itself), its rules read the same way.
 *
 * A rule is mapped to what the user chose (filter key, operator, value, columns, scope and its params, the premade
 * key); its class, SQL column and premade parameters are left behind: StateCodec rebuilds them from the searchable. A
 * rule that can't be read (an enum case removed since: unserialize() fails; a rule without filter key) becomes a
 * ['t' => 'legacy', 'reason' => ...] entry, dropped and reported by StateCodec, never stored.
 */
final class LegacyStateReader
{
    /** Removed since: its premade rules are read by key (they were silently lost). */
    const REMOVED_PREMADE_CLASSES = ['DefaultRuleWrapper'];

    /**
     * Null when it isn't a state (its entity isn't a class name: an object read as an incomplete class...). A missing
     * or null entity or search is '', as v1 arrays were always read.
     */
    public static function fromArray(array $data): ?array
    {
        $state = static::state($data['searchableEntity'] ?? '', $data['search'] ?? '', $data['rules'] ?? []);

        return $state === null ? null : $state + static::extras($data);
    }

    public static function fromObject(object $state): ?array
    {
        $props = static::props($state);
        $state = static::state($props['searchableEntity'] ?? null, $props['search'] ?? null, $props['rules'] ?? []);

        return $state === null ? null : $state + static::extras($props);
    }

    protected static function state($entity, $search, $rules): ?array
    {
        if ($entity !== null && !is_string($entity)) {
            return null;
        }

        $rules = $rules instanceof \Traversable ? iterator_to_array($rules, false) : (is_array($rules) ? array_values($rules) : []);

        return [
            'v' => StateCodec::VERSION,
            // As stored (null, '' or a class name): StateCodec::decode() checks the class.
            'entity' => $entity,
            'search' => is_scalar($search) ? (string) $search : null,
            // A rule stored without id is given its position ('l0', 'l1'...): the same on every read of that state.
            'rules' => collect($rules)->map(fn($rule, $i) => static::rule(
                is_string($rule) ? @unserialize($rule, ['allowed_classes' => false]) : $rule,
                'l' . $i,
            ))->values()->all(),
        ];
    }

    /** What some stored states hold besides their rules: the header sort, the open flag, a recent search's list data. */
    protected static function extras(array $data): array
    {
        return array_filter([
            'sort' => is_string($data['sort'] ?? null) ? $data['sort'] : null,
            'open' => ($data['open'] ?? null) === true ? true : null,
            'filtersCount' => is_int($data['filtersCount'] ?? null) ? $data['filtersCount'] : null,
            'filters' => is_array($data['filters'] ?? null) ? array_values(array_filter($data['filters'], 'is_string')) : null,
        ], fn($value) => $value !== null);
    }

    /** One rule (an incomplete class, or a real object from a session) as v2 rule data. */
    protected static function rule($rule, string $positionId): array
    {
        if (!is_object($rule)) {
            // unserialize() failed: an enum case removed or renamed since (OperatorEnum), or a damaged string.
            return ['t' => 'legacy', 'reason' => 'undecodable'];
        }

        $props = static::props($rule);
        $class = $rule instanceof \__PHP_Incomplete_Class ? (string) ($props['__PHP_Incomplete_Class_Name'] ?? '') : get_class($rule);
        $id = Rule::isValidId($props['id'] ?? null) ? $props['id'] : $positionId;

        if (static::isPremadeClass($class)) {
            return is_scalar($props['key'] ?? null) && (string) $props['key'] !== ''
                ? ['id' => $id, 't' => 'premade', 'key' => (string) $props['key']]
                : ['t' => 'legacy', 'reason' => 'malformed', 'class' => class_basename($class)];
        }

        if (!is_string($props['keyReference'] ?? null) || $props['keyReference'] === '') {
            return ['t' => 'legacy', 'reason' => 'not_a_filter', 'class' => class_basename($class)];
        }

        $data = ['id' => $id, 't' => 'filter', 'key' => $props['keyReference']];

        if (array_key_exists('scope', $props)) {
            // A scope rule: the scope of a select-scope filter is its value, a scope filter's inputs are its params.
            $data['value'] = static::plain($props['scope']);
            $params = static::plain($props['params'] ?? []);
            $data += is_array($params) && $params ? ['params' => array_values($params)] : [];
        } else {
            $operator = $props['operator'] ?? null;
            $operator = $operator instanceof \BackedEnum ? $operator->value : (is_int($operator) ? $operator : null);
            $data += $operator !== null ? ['op' => $operator] : [];
            $data['value'] = static::plain($props['value'] ?? null);

            if (array_key_exists('columns', $props)) {
                $data['columns'] = array_values(array_filter((array) static::plain($props['columns']), 'is_string'));
            }
        }

        return $data + (!empty($props['editing']) ? ['editing' => true] : []);
    }

    /** A premade rule: PremadeRuleWrapper (or a host subclass), or a class removed since (read by its key). */
    protected static function isPremadeClass(string $class): bool
    {
        if (in_array(class_basename($class), array_merge(['PremadeRuleWrapper'], static::REMOVED_PREMADE_CLASSES), true)) {
            return true;
        }

        // A class name read from stored data: checked before the autoloader turns it into a file path.
        return StateCodec::isClassName($class) && class_exists($class) && is_subclass_of($class, PremadeRuleWrapper::class);
    }

    /** Scalars, null and arrays of them; an enum as its value. Anything else (an object of any class) is null. */
    protected static function plain($value)
    {
        return match (true) {
            $value === null, is_scalar($value) => $value,
            $value instanceof \BackedEnum => $value->value,
            is_array($value) => array_map(fn($item) => static::plain($item), $value),
            default => null,
        };
    }

    /** An object's properties by name ("\0*\0name" => "name"), whatever their visibility. */
    protected static function props(object $object): array
    {
        $props = [];

        foreach ((array) $object as $key => $value) {
            $props[preg_replace('/^\0[^\0]+\0/', '', (string) $key)] = $value;
        }

        return $props;
    }
}
