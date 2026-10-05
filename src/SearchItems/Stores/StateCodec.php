<?php

namespace Kompo\Searchbar\SearchItems\Stores;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Kompo\Searchbar\Searchable\Searchable;
use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;
use Kompo\Searchbar\SearchItems\Rules\PremadeRuleWrapper;
use Kompo\Searchbar\SearchItems\Rules\Rule;
use Kompo\Searchbar\SearchItems\Rules\UnusableRuleData;
use Kompo\Searchbar\SearchService;

/**
 * Stored search states as plain data (format v2), rebuilt through the searchable's current filterables and premade
 * rules when read. Every store writes it: the session (an array), and the search_states rows as JSON (remembered
 * tables, links and shared views, favorites, recent searches).
 *
 *     {"v":2,"entity":"App\\Models\\Crm\\Person","search":"martin","rules":[
 *         {"id":"l0","t":"premade","key":"active_default"},
 *         {"id":"r1a2b3c4","t":"filter","key":"email_filter","op":10,"value":"gmail"},
 *         {"id":"r9f8e7d6","t":"filter","key":"type_filter","value":"hasScoutTeamOccupation"}]}
 *
 * - No class name but the entity's, used only when it is a Searchable class: a stored rule was a serialized object
 *   (class names, its SQL column, its SearchService), unserialized on every read.
 * - A rule keeps its id, its filter key and what the user chose (operator, value, columns, a scope's params). Its
 *   class and column come from the filterable when read (Filterable::ruleFromData()): a filter whose column changed
 *   since (SISC's scout years, owner filters, invoice amount) uses the current one. A premade rule is its key: rebuilt,
 *   it applies to the viewer's current team (owner decision).
 * - A rule that can't be rebuilt is dropped and logged (searchbar.stored_rules_dropped, with the reason): a filter or
 *   premade key the searchable no longer declares, an operator the field doesn't offer, an undeclared scope...
 * - Working states (session, remembered tables, a results page's link) keep pending pills, open editors, the header
 *   sort and the open flag; snapshots (favorites, shared views, recent searches, link copies) don't.
 * - v1 (serialized rules) is still read everywhere, without instantiating any of its classes (LegacyStateReader);
 *   searchbar:migrate-states converts the stored rows.
 */
final class StateCodec
{
    const VERSION = 2;

    /** Longest stored v2 state read or written (searchbar.max-state-kb): no store writes a larger one. */
    public static function maxBytes(): int
    {
        return max(1, (int) config('searchbar.max-state-kb', 256)) * 1024;
    }

    // ENCODING

    /**
     * $state as v2 data. $snapshot: without pending pills, open editors, the header sort and the open flag (favorites,
     * shared views, recent searches); else the working state.
     */
    public static function encode(SearchState $state, bool $snapshot = false): array
    {
        $rules = [];

        foreach ($state->storedRules() as $rule) {
            if ($snapshot && $rule instanceof FilterableRule && $rule->isPendingValue()) {
                continue;
            }

            if (($data = static::encodeRule($rule, $snapshot)) === null) {
                // Not a filter of the searchable (a host rule added by hand): it couldn't be rebuilt when read.
                Log::warning('searchbar.rule_not_stored', ['class' => get_class($rule)]);

                continue;
            }

            $rules[] = $data;
        }

        $entity = $state->getSearchableEntity();
        $search = $state->getSearch();

        // Null stays null (no entity picked, no text): read back as it was.
        return array_merge([
            'v' => static::VERSION,
            'entity' => is_string($entity) ? $entity : null,
            'search' => is_scalar($search) ? (string) $search : null,
            'rules' => $rules,
        ], $snapshot ? [] : array_filter([
            'sort' => $state->getSort(),
            'open' => $state->isOpen() ? true : null,
        ], fn($value) => $value !== null));
    }

    /** One rule as v2 data, or null when it isn't a premade rule or a filter with a key. */
    public static function encodeRule(Rule $rule, bool $snapshot = false): ?array
    {
        if ($rule instanceof PremadeRuleWrapper) {
            return ['id' => $rule->getId(), 't' => 'premade', 'key' => (string) $rule->getKey()];
        }

        $key = $rule instanceof FilterableRule ? $rule->getKeyReference() : null;

        if (!is_string($key) || $key === '') {
            return null;
        }

        return ['id' => $rule->getId(), 't' => 'filter', 'key' => $key]
            + static::plain($rule->toData())
            + (!$snapshot && $rule->isEditing() ? ['editing' => true] : []);
    }

    /** v2 data as the JSON a search_states row holds. */
    public static function toJson(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    // DECODING

    /**
     * A stored state (any format: see toV2()) rebuilt as a SearchState bound to $service, or null when it isn't one
     * (not a state, over maxBytes(), or its entity isn't a Searchable class: refused and logged). Its rules are rebuilt
     * through the entity's filterables and premade rules; those that can't be are dropped and logged
     * (SearchState::droppedStoredRules() lists them). $log false: nothing is logged (searchbar:migrate-states reports).
     */
    public static function decode($payload, SearchService $service, bool $log = true): ?SearchState
    {
        $data = static::toV2($payload, $log);

        if ($data === null) {
            return null;
        }

        // No entity (null or '', kept as stored), else a Searchable class: it is instantiated.
        $entity = $data['entity'] ?? null;

        if ($entity !== null && $entity !== '' && !static::isSearchableClass($entity)) {
            $log && Log::warning('searchbar.stored_state_refused', ['reason' => 'entity', 'entity' => is_string($entity) ? mb_substr($entity, 0, 255) : gettype($entity)]);

            return null;
        }

        $state = (new SearchState())->injectContext($service)
            ->setSearchableEntity($entity)
            ->setSearch(SearchService::capSearchText(is_scalar($data['search'] ?? null) ? (string) $data['search'] : null))
            ->setSort(is_string($data['sort'] ?? null) ? $data['sort'] : null)
            ->setOpen(($data['open'] ?? null) === true);

        [$rules, $dropped] = static::rebuildRules(is_array($data['rules'] ?? null) ? array_values($data['rules']) : [], $state);

        $state->setRules($rules)->setDroppedStoredRules($dropped);

        if ($dropped && $log) {
            Log::warning('searchbar.stored_rules_dropped', ['entity' => $entity, 'rules' => $dropped]);
        }

        return $state;
    }

    /**
     * Any stored state as v2 data, or null when it isn't one. Nothing is instantiated:
     * - a v2 JSON string (a search_states row), at most maxBytes();
     * - a serialized array (a v1 row; a v2 array serialized is read as v2);
     * - an array or a collection: v2 (the session, a snapshot), else v1 (LegacyStateReader);
     * - a SearchState object: the session of a release before v2.
     */
    public static function toV2($payload, bool $log = true): ?array
    {
        if ($payload instanceof Collection) {
            $payload = $payload->all();
        }

        if (is_string($payload)) {
            return static::fromString($payload, $log);
        }

        if (is_object($payload)) {
            return $payload instanceof SearchState || $payload instanceof \__PHP_Incomplete_Class
                ? LegacyStateReader::fromObject($payload)
                : null;
        }

        if (!is_array($payload)) {
            return null;
        }

        return ($payload['v'] ?? null) === static::VERSION ? $payload : LegacyStateReader::fromArray($payload);
    }

    /**
     * The entity, search text and list data (a recent search's filtersCount and filters) of a stored state, without
     * reading its rules (lists of favorites and recent searches): no 'rules' key. Null when it isn't a state.
     */
    public static function header($payload): ?array
    {
        if (is_string($payload) && str_starts_with(ltrim($payload), 'a:')) {
            // v1: the outer array only (its rules stay serialized strings).
            $data = @unserialize($payload, ['allowed_classes' => false]);
            $payload = is_array($data) ? array_diff_key($data, ['rules' => true]) : null;
        }

        $data = $payload === null ? null : static::toV2($payload);

        return $data === null ? null : array_diff_key($data, ['rules' => true]);
    }

    protected static function fromString(string $raw, bool $log): ?array
    {
        $raw = ltrim($raw);

        if (str_starts_with($raw, '{')) {
            // Never written that large (the stores check it): a state made by hand is refused, not decoded.
            if (strlen($raw) > static::maxBytes()) {
                $log && Log::warning('searchbar.stored_state_refused', ['reason' => 'too_large', 'bytes' => strlen($raw)]);

                return null;
            }

            $data = json_decode($raw, true, 16);

            return is_array($data) && ($data['v'] ?? null) === static::VERSION ? $data : null;
        }

        if (str_starts_with($raw, 'a:')) {
            $data = @unserialize($raw, ['allowed_classes' => false]);

            return is_array($data) ? static::toV2($data, $log) : null;
        }

        return null;
    }

    /** [rules, dropped ([key, reason] each)] of $items rebuilt for $state's entity (else the default results entity). */
    protected static function rebuildRules(array $items, SearchState $state): array
    {
        $rules = collect();
        $dropped = [];

        if (!$items) {
            return [$rules, $dropped];
        }

        $searchable = $state->getSearchableInstanceForResultsPanel();
        // Built once for this state (each call builds them all again), not kept beyond it: getFilterable() assigns the
        // rule it renders to its filterable (a select-scope's label reads it), rules sharing one would show each other's.
        $filterables = null;
        $premades = null;
        $seenPremades = [];

        foreach ($items as $i => $item) {
            $key = is_array($item) && is_scalar($item['key'] ?? null) ? (string) $item['key'] : null;

            try {
                if (!is_array($item) || ($item['t'] ?? null) === 'legacy') {
                    throw new UnusableRuleData(is_array($item) ? (string) ($item['reason'] ?? 'undecodable') : 'malformed');
                }

                if (!$searchable) {
                    throw new UnusableRuleData('no_entity');
                }

                if ($key === null || $key === '') {
                    throw new UnusableRuleData('malformed');
                }

                if (($item['t'] ?? null) === 'premade') {
                    $premades ??= $searchable->getPremadeRules()->keyBy(fn($premade) => (string) $premade->getKey());
                    $rule = $premades->get($key) ?? throw new UnusableRuleData('unknown_premade');

                    if (isset($seenPremades[$key])) {
                        throw new UnusableRuleData('duplicate_premade');
                    }

                    $seenPremades[$key] = true;
                } elseif (($item['t'] ?? null) === 'filter') {
                    $filterables ??= $searchable->decoratedFilterables();
                    $filterable = $filterables[$key] ?? null;

                    if (!$filterable instanceof Filterable) {
                        throw new UnusableRuleData('unknown_filter');
                    }

                    $rule = $filterable->ruleFromData($item)->setKeyReference($key)->setEditing(($item['editing'] ?? null) === true);
                } else {
                    throw new UnusableRuleData('malformed');
                }
            } catch (UnusableRuleData $e) {
                $dropped[] = array_filter(['key' => $key, 'reason' => $e->getMessage(), 'detail' => $e->detail,
                    'class' => is_array($item) ? ($item['class'] ?? null) : null]);

                continue;
            } catch (\Throwable $e) {
                // A host filterable failing on this data: the rule goes, the state stays usable. Its class only (the
                // message can hold values): reported in full.
                report($e);
                $dropped[] = array_filter(['key' => $key, 'reason' => 'error', 'detail' => class_basename($e)]);

                continue;
            }

            // Missing (a state written without ids): its position, the same on every read of that stored state.
            $rules->push($rule->setId(Rule::isValidId($item['id'] ?? null) ? $item['id'] : 'l' . $i));
        }

        return [$rules, $dropped];
    }

    /**
     * The dropped rules ($state->droppedStoredRules()) that failed to rebuild (a filterable or the premade rules
     * throwing, e.g. without a signed-in user; no searchable to rebuild with) rather than no longer fitting: their
     * stored data may be fine, so a state that lost them is not written back in bulk (searchbar:migrate-states).
     */
    public static function failedDrops(array $dropped): array
    {
        return array_values(array_filter($dropped, fn($rule) => in_array($rule['reason'] ?? null, ['error', 'no_entity'], true)));
    }

    // CHECKS

    /** A class name as PHP writes it (no leading backslash): nothing else reaches the autoloader (file paths). */
    public static function isClassName($class): bool
    {
        return is_string($class) && strlen($class) <= 255
            && preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $class) === 1;
    }

    /**
     * The only class a stored state names: instantiated when read, so a concrete Searchable class only. Any one, not
     * only the navbar's registered searchables (architecture §4.2 asked for those): a table's own searchable is stored
     * too (SISC's members lists: TeamMemberSearchable, not registered). A class this process already defined (an
     * anonymous one in the session of the same request...) is looked up without the autoloader; any other name must be
     * a class name for the autoloader to look it up. Named as PHP names it (no leading backslash): the registered
     * searchables are compared by name.
     */
    public static function isSearchableClass($class): bool
    {
        return is_string($class) && strlen($class) <= 1024 && !str_starts_with($class, '\\')
            && (class_exists($class, false) || (static::isClassName($class) && class_exists($class)))
            && is_subclass_of($class, Searchable::class) && !(new \ReflectionClass($class))->isAbstract();
    }

    /** Scalars, null and arrays of them; an enum as its value; a non-finite float (JSON has none) or an object: null. */
    public static function plain($value)
    {
        return match (true) {
            is_float($value) => is_finite($value) ? $value : null,
            $value === null, is_scalar($value) => $value,
            $value instanceof \BackedEnum => $value->value,
            is_array($value) => array_map(fn($item) => static::plain($item), $value),
            default => null,
        };
    }
}
