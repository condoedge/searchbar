<?php

namespace Kompo\Searchbar\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Kompo\Searchbar\Facades\SearchStateModel;
use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\EntityType\RelationEntityType;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumn;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\OperatorEnum;
use Kompo\Searchbar\SearchItems\Filterables\FilterableSelectScope;
use Kompo\Searchbar\SearchItems\Rules\ColumnRule\WithEntityRule;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;
use Kompo\Searchbar\SearchItems\Rules\PremadeRuleWrapper;
use Kompo\Searchbar\SearchItems\Rules\RulesService;
use Kompo\Searchbar\SearchItems\Rules\ScopeRule;
use Kompo\Searchbar\SearchItems\Stores\RecentSearches;
use Kompo\Searchbar\SearchItems\Stores\SearchState;
use Kompo\Searchbar\SearchItems\Stores\SearchStore;
use Kompo\Searchbar\SearchItems\Stores\TableStore;
use Kompo\Searchbar\SearchService;

/**
 * The searchstate/* actions. A request about one rule names it by its id (?ruleId, with its filter key ?key), never by
 * its position: positions shifted when rules changed in another tab or request. An id no rule has, or whose rule has
 * another key than the posted one, changes nothing. Pages rendered before ids post ?i (+ ?key): served for one release
 * (logged searchbar.legacy_index_request).
 *
 * Every action changing the state is a read-modify-write of the state as it is now (mutate(): SearchStore::mutate(),
 * the row locked from its read to its write), not as it was when the request started: another request may have
 * changed it meanwhile (a pill applied, a chip clicked, another tab, an option search).
 */
class SearchStateController extends Controller
{

    protected $state;
    protected $serviceKey;
    protected $searchableInstance;
    protected $storeKey;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            // Pills put their own state's keys in the URL (they can sit in another komponent's form): those win.
            $this->storeKey = $request->query('storeKey', $request->input('storeKey'));
            $this->serviceKey = $this->getServiceKey();

            searchService($this->serviceKey)->setStoreKey($this->storeKey);
            $store = stateStore($this->serviceKey);

            // A remembered table's row is created by the table's own display, never here: any tbl. key posted would
            // add a row kept for months. Without the user's row, a no-op (as an unknown rule id).
            if ($store instanceof TableStore && !$store->hasRow()) {
                return response('');
            }

            return $next($request);
        });
    }

    protected function getServiceKey()
    {
        return request()->query('serviceKey', request('serviceKey')) ?? SearchService::DEFAULT_KEY;
    }

    /**
     * Runs $change on the latest state (read again under the store's lock) and stores it: SearchStore::mutate().
     * $this->state and $this->searchableInstance are that state's while it runs (the entity may have changed since the
     * request started). $change returning false: nothing to store. It may run twice (a first write racing another).
     */
    protected function mutate(callable $change): void
    {
        $store = stateStore($this->serviceKey);

        $store->mutate(function (SearchState $state) use ($change, $store) {
            // A remembered table's row gone meanwhile (pruned, trimmed): a request never creates it.
            if ($store instanceof TableStore && !$store->hasRow()) {
                return false;
            }

            $this->state = $state;
            $this->searchableInstance = $state->getSearchableInstance();

            return $change($state);
        });
    }

    /** The state as it is, for an action that doesn't change it. */
    protected function readState(): void
    {
        $this->state = stateStore($this->serviceKey)->getState();
        $this->searchableInstance = $this->state->getSearchableInstance();
    }

    public function closeSearch()
    {
        $this->mutate(fn($state) => $state->setOpen(false));
    }

    public function openSearch()
    {
        $this->mutate(fn($state) => $state->setOpen(true));
    }

    public function setSearch()
    {
        $this->mutate(fn($state) => $state->setSearch(SearchService::capSearchText(request('search'))));
    }

    /**
     * A navbar search was used (searchbarClientJs(): Enter, a result opened from the panel): it becomes one of the user's
     * recent searches (RecentSearches). ?search is the text used (the stored one may be newer: typed meanwhile). The
     * state doesn't change. Only the navbar's: a table's state (its service, or a remembered table's key) never.
     * Nor a text too short to search (search-min-chars): the panel ran nothing, a row "ab" would put back a hint. Gated
     * here, not in RecentSearches::remember(): "Open in a table" searches any text (tables aren't gated).
     */
    public function rememberSearch()
    {
        if ($this->serviceKey !== SearchService::DEFAULT_KEY || TableStore::handles($this->storeKey)) {
            return response()->noContent();
        }

        $this->readState();

        // Posted empty ("" comes as null): an empty text, not the stored one.
        $search = request()->has('search') ? (is_string(request('search')) ? request('search') : '') : null;

        if (!SearchService::textTooShort($search ?? $this->state->getSearch())) {
            RecentSearches::remember($this->state, $search);
        }

        return response()->noContent();
    }

    public function getBack()
    {
        $this->mutate(fn($state) => $state->setSearchableEntity(null)->setRules(collect([])));
    }

    /** A table's "Reset filters": back to its entity's default rules, on the same entity (get-back clears the entity). */
    public function resetRules()
    {
        $this->mutate(function ($state) {
            if (!$this->searchableInstance) {
                return false;
            }

            $state->setRules($this->searchableInstance->getDefaultRulesApplied()->values());
            $state->setSearch(null);
        });
    }

    public function selectSearchableEntity()
    {
        $entity = request('searchableEntity');

        // Only a registered searchable: the class name is instantiated. (Hosts register them on the default service.)
        $searchables = searchService(SearchService::DEFAULT_KEY)->getSearchables()->merge(searchService($this->serviceKey)->getSearchables());

        if (!is_string($entity) || !$searchables->contains($entity)) {
            return;
        }

        $this->mutate(function ($state) use ($entity) {
            $store = stateStore($this->serviceKey);
            $state->setSearchableEntity($entity);

            // getInitialRules() reads the store's state again: the entity must be there. Stored first unless the store
            // hands back this same object (a DatabaseStore does; a session without state gives a new empty one).
            if ($store->getState() !== $state) {
                $store->storeState($state);
            }

            $state->setRules(collect($state->getSearchableInstance()->getInitialRules())->filter());
            $state->setSearch(null);
        });
    }

    public function deleteRule()
    {
        $this->mutate(function ($state) {
            [$id] = $this->ruleFromRequest();

            if ($id === null) {
                return false;
            }

            $state->removeRule($id);
        });
    }

    public function toggleDefaultRule()
    {
        $name = request('key');

        if (!is_string($name)) {
            return;
        }

        $value = request('toggle' . str_replace('.', '_', $name));

        $this->mutate(function ($state) use ($name, $value) {
            $rule = $this->searchableInstance ? PremadeRuleWrapper::findByKey($this->searchableInstance, $name) : null;

            if (!$rule) {
                return false;
            }

            $value = $rule->isInverse() ? !$value : $value;
            $active = $rule->findActiveIn($state->getRules());

            if ($value && !$active) {
                $state->addRuleAtFirst($rule);
            } elseif (!$value && $active) {
                $state->removeRule($active->getId());
            } else {
                // Already as asked (a double click, another tab).
                return false;
            }
        });
    }

    /** Custom filters modal: the row's value (fields are named per rule, value_{id}). */
    public function setRuleValue()
    {
        $this->mutate(fn() => $this->applyRuleValue());
    }

    protected function applyRuleValue()
    {
        [$id, $rule] = $this->ruleFromRequest();

        if (!$rule instanceof FilterableRule) {
            return false;
        }

        $value = Filterable::postedRuleField('value_', $id);

        if ($rule instanceof ScopeRule) {
            // The scope is called on the query: only one the filter declares (the value used to be any method name).
            $filterable = $rule->getFilterable($this->searchableInstance);
            $value = $filterable && method_exists($filterable, 'isAllowedScope') && $filterable->isAllowedScope($value) ? $value : null;
        } elseif (($filterable = $rule->getFilterable($this->searchableInstance)) && method_exists($rule, 'getOperator')) {
            $value = $filterable->normalizeInlineValue($value, $rule->getOperator());
        }

        // Not `if ($value)`: "0" is a value.
        if ($value !== null && $value !== '' && $value !== []) {
            $rule->setValue($value);

            $this->state->replaceRule($id, $rule);
        } else {
            // If the value is empty, we remove the rule
            $this->state->removeRule($id);
        }
    }

    public function setRuleParam()
    {
        $this->mutate(function ($state) {
            [$id, $rule] = $this->ruleFromRequest();

            if (!$rule instanceof ScopeRule) {
                return false;
            }

            $params = Filterable::postedRuleField('param_', $id);
            $rule->setParams(collect((array) $params)->filter(fn($param) => is_null($param) || is_scalar($param))->values()->all());

            $state->replaceRule($id, $rule);
        });
    }

    /**
     * @deprecated nothing renders it (option chips post toggle-section-rule, rule forms add their own rule): removed in
     * the next release.
     */
    public function addRule()
    {
        Log::notice('searchbar.deprecated_route', ['route' => 'searchstate.add-rule']);

        $rule = RulesService::retrieveRuleFromRequest('rule');

        if (!$rule) {
            return;
        }

        // Its own id (the posted rule may carry one of this state's). A copy per run (mutate() may run it twice).
        $this->mutate(fn($state) => $state->addRule((clone $rule)->setId(null)));
    }

    /**
     * A "Search by" chip (SearchColumnSection). The rule is built here from the filter key (no serialized rule
     * travels through the browser):
     * - text in the searchbar that fits the field (a name, "5-10" for a number...) becomes the field's filter,
     *   applied at once, and the free text is consumed so it doesn't also filter as a name search;
     * - no text: a new pill opens its editor;
     * - text that doesn't fit (letters for a date): the editor opens and the text keeps filtering as typed.
     * On an already selected field: no text removes it (toggle), fitting text replaces its value, else its editor opens.
     * A table's "Filter" menu (?add) adds a condition instead (addColumnCondition()).
     *
     * The chip's form can't carry the navbar input (SearchPanel is another komponent), so the stored search is
     * used: chips are locked while typed text is searched (searchbarLoadingOn/Off), so it is the typed one.
     */
    public function columnChip()
    {
        $this->mutate(fn() => $this->applyColumnChip());
    }

    protected function applyColumnChip()
    {
        $key = request('key');
        $filterable = $this->searchableInstance && is_string($key)
            ? rescue(fn() => $this->searchableInstance->filterable($key), null, false)
            : null;

        if (!$filterable instanceof FilterableColumn) {
            return false;
        }

        // The live text: the navbar input (search) or a table's search box (searchbar_search, sent by its Query),
        // else the stored search.
        // A posted box counts even when emptied (sent as null): the stored search may be older text.
        $raw = match (true) {
            request()->has('search') => request('search') ?? '',
            request()->has('searchbar_search') => request('searchbar_search') ?? '',
            default => $this->state->getSearch(),
        };
        $search = trim((string) SearchService::capSearchText($raw));

        if (request('add')) {
            return $this->addColumnCondition($key, $filterable, $search);
        }

        $params = $search === '' ? null : $filterable->valueFromSearchText($search);
        $existing = $this->state->getFilterableRules()->first(fn($r) => $r->getKeyReference() === $key);

        if ($params) {
            // Replaced in place: the rule keeps its id.
            $rule = $filterable->getRuleInstance($params)->setKeyReference($key);
            $existing ? $this->state->replaceRule($existing->getId(), $rule) : $this->state->addRule($rule);
            $this->state->setSearch(null);
        } elseif ($existing) {
            $search === ''
                ? $this->state->removeRule($existing->getId())
                : $this->openEditor($existing->getId());
        } else {
            $this->closeEditors();
            $this->state->addRule($filterable->getRuleInstance(['value' => null])->setKeyReference($key));
        }
    }

    /**
     * A table's "Filter" menu: the typed text becomes one more condition on the field, never a replacement. Conditions
     * on one field AND together (">= 5", then "<= 10"), except options: picked into the field's IN rule (two IN rules
     * would AND to nothing). A condition the field already has (same rule, operator and value) isn't added twice.
     * Text that can't be a value of the field opens the field's pending pill, or a new one (the text stays in the box).
     * Without text there is nothing to add (no empty pill; the menu disables its fields while the box is empty).
     */
    protected function addColumnCondition(string $key, FilterableColumn $filterable, string $search): bool
    {
        if ($search === '') {
            return false;
        }

        $sameField = $this->state->getFilterableRules()->filter(fn($r) => $r->getKeyReference() === $key)->values();
        $params = $filterable->valueFromSearchText($search);

        if (!$params) {
            $pending = $sameField->first(fn($r) => $r->isPendingValue());

            if ($pending) {
                $this->openEditor($pending->getId());
            } else {
                $this->closeEditors();
                $this->state->addRule($filterable->getRuleInstance(['value' => null])->setKeyReference($key));
            }

            return true;
        }

        $rule = $filterable->getRuleInstance($params)->setKeyReference($key);
        $operator = fn($r) => method_exists($r, 'getOperator') ? $r->getOperator() : null;
        $alike = fn($r) => get_class($r) === get_class($rule) && $operator($r) === $operator($rule) && !$r->isPendingValue();
        $in = $operator($rule) === OperatorEnum::IN ? $sameField->first($alike) : null;

        if ($in) {
            // Loose: an option key posted as '1' is the stored 1.
            $in->setValue(collect((array) $in->getValue())->concat((array) $rule->getValue())->unique()->values()->all());
            $this->state->replaceRule($in->getId(), $in->setEditing(false));
        } elseif (!$sameField->contains(fn($r) => $alike($r) && static::sameValue($r->getValue(), $rule->getValue()))) {
            $this->state->addRule($rule);
        }

        // Used as the filter: it no longer also searches as typed.
        $this->state->setSearch(null);

        return true;
    }

    /**
     * Two rule values are the same condition: compared strictly once normalized, scalars as strings (5 and 5.0 and '5'
     * are one number) and arrays item by item. Loosely, '1e3' equaled '1000' (numeric strings compare as numbers).
     */
    protected static function sameValue($a, $b): bool
    {
        $normalize = function ($value) use (&$normalize) {
            return match (true) {
                is_array($value) => array_map($normalize, $value),
                is_scalar($value) => (string) $value,
                default => $value,
            };
        };

        return $normalize($a) === $normalize($b);
    }

    /**
     * An option chip (SearchEntitySection, SearchSelectScopeSection), in the navbar panel or a table's "Filter" menu:
     * its rule toggles in the state. The chip posts its filter key and option (?key, ?option), the rule is built here
     * by the state's entity. A host section (or a page rendered before) posts a signed rule: only a filter of the
     * state's entity, as that entity builds it.
     */
    public function toggleSectionRule()
    {
        $this->mutate(fn() => $this->applyToggleSectionRule());
    }

    protected function applyToggleSectionRule()
    {
        $rule = request()->has('option') ? $this->sectionOptionRule() : RulesService::retrieveRuleFromRequest('rule');

        if (!$rule instanceof FilterableRule || !$this->isOwnSectionRule($rule)) {
            return false;
        }

        // Option chips of one field (Gender: Male, Female) share one IN rule: a rule per chip ANDed them (no result).
        // Only the field's IN rule: merged into a "not in" rule, the option inverted it ("not in (Female, Male)").
        if ($rule instanceof WithEntityRule && is_array($rule->getValue())) {
            $option = collect($rule->getValue())->first();
            $existing = $this->state->getFilterableRules()->first(fn($r) => $r instanceof WithEntityRule
                && $r->getKeyReference() === $rule->getKeyReference() && $r->getOperator() === OperatorEnum::IN);

            if (!$existing) {
                // Its own rule, next to an exclusion of the same field (which the chip doesn't show as selected).
                $this->state->addRule($rule->setId(null));

                return true;
            }

            $values = collect($existing->getValue())->reject(fn($v) => $v === null || $v === '')->values();

            $values = $values->contains(fn($v) => $v == $option)
                ? $values->reject(fn($v) => $v == $option)->values()
                : $values->push($option);

            if ($values->isEmpty()) {
                $this->state->removeRule($existing->getId());
            } else {
                $existing->setValue($values->all());
                $this->state->replaceRule($existing->getId(), $existing->setEditing(false));
            }

            return true;
        }

        $existing = $this->state->getFilterableRules()->first(function ($r) use ($rule) {
            return $r instanceof FilterableRule
                && get_class($r) === get_class($rule)
                && $r->getKeyReference() === $rule->getKeyReference()
                && $r->getValue() == $rule->getValue();
        });

        if ($existing) {
            $this->state->removeRule($existing->getId());
        } else {
            // Its own id (a signed chip rule could carry one of this state's).
            $this->state->addRule($rule->setId(null));
        }
    }

    /**
     * The rule of an option chip (?key, ?option), built by the state's entity: one of the declared scopes of a
     * select-scope filter, or one option of an option field (one of its options; a relation's are whole tables: an id).
     * Null for anything else.
     */
    protected function sectionOptionRule(): ?FilterableRule
    {
        $key = request('key');
        $option = request('option');
        $filterable = $this->searchableInstance && is_string($key) && is_scalar($option) && (string) $option !== ''
            ? rescue(fn() => $this->searchableInstance->filterable($key), null, false)
            : null;

        if ($filterable instanceof FilterableSelectScope) {
            return $filterable->isAllowedScope($option) ? $filterable->getRuleInstance(['scope' => $option])->setKeyReference($key) : null;
        }

        $entityType = $filterable instanceof FilterableColumn && $filterable->getInputType()->offersOptions() ? $filterable->getEntityType() : null;

        if (!$entityType) {
            return null;
        }

        // The option as the options key it (an enum's int): a chip posts it as text.
        $value = $entityType instanceof RelationEntityType
            ? (ctype_digit((string) $option) ? (int) $option : (string) $option)
            : collect($entityType->optionsWithLabels())->keys()->first(fn($optionKey) => (string) $optionKey === (string) $option);

        return $value === null ? null : $filterable->getRuleInstance(['value' => [$value]])->setKeyReference($key);
    }

    /**
     * A chip's rule is a filter of the state's entity, of the class and on the column (or scope) that entity's filter
     * builds for it. Chips are signed with the app key whatever their entity: a chip of another entity's filter with
     * the same key (Task and Invoice both have a status_filter) brought its own column into this state's query.
     */
    protected function isOwnSectionRule(FilterableRule $rule): bool
    {
        $filterable = $this->searchableInstance ? $rule->getFilterable($this->searchableInstance) : null;

        if (!$filterable) {
            return false;
        }

        $own = rescue(fn() => $filterable->getRuleInstance($rule instanceof ScopeRule ? ['scope' => $rule->getValue()] : ['value' => $rule->getValue()]), null, false);

        // Loose on the column: an Expression column is another object of the same value.
        return $own instanceof FilterableRule && get_class($own) === get_class($rule)
            && ($own->toArray()[0] ?? null) == ($rule->toArray()[0] ?? null);
    }

    /**
     * A table's "Views" menu (HasSearchbarViews): a favorite listed to the user (own or global) of the state's entity
     * replaces the state's filters and search text. Cleaned as a reopened state: no pending pill or open editor, no
     * filter the entity no longer declares, premade rules rebuilt for the viewer's current team.
     */
    public function loadFavorite()
    {
        $this->mutate(fn() => $this->applyLoadFavorite());
    }

    protected function applyLoadFavorite()
    {
        $id = request('id');
        $entity = $this->state->getSearchableEntity();

        if (!$entity || !$this->searchableInstance || !is_scalar($id) || !ctype_digit((string) $id)) {
            return false;
        }

        // Decoded from the row the favorites list's query finds (DbStore would read it again, through another check).
        $favorite = SearchStateModel::getAllForUser()->find((int) $id);
        $data = SearchStore::decodeRow($favorite);

        if (!$data || ($data['entity'] ?? null) !== $entity) {
            return false;
        }

        // Rebuilt through the entity's current filterables (a rule that no longer fits is dropped, logged). One that
        // isn't a state any more, or fails to rebuild: the table keeps its filters (and is refreshed, unlocking its
        // bar) instead of a 500.
        $saved = rescue(fn() => stateStore($this->serviceKey)->stateFromArray($data), null, true);

        if (!$saved) {
            Log::warning('searchbar.favorite_undecodable', ['favorite' => $favorite->id]);

            return false;
        }

        $this->state->setRules($saved->getRules()->values())->setSearch($saved->getSearch());
        $this->state->cleanForReopen($this->searchableInstance);
    }

    /**
     * A table's column header (HasSearchbarColumnHeaders::searchbarTh()): the pill editor of the field's last condition
     * opens, else a new pending pill of the field (its editor opens). Only a column filter with a pill editor.
     */
    public function columnFilter()
    {
        $this->mutate(fn() => $this->applyColumnFilter());
    }

    protected function applyColumnFilter()
    {
        $key = request('key');
        $filterable = $this->searchableInstance && is_string($key)
            ? rescue(fn() => $this->searchableInstance->filterable($key), null, false)
            : null;

        if (!$filterable instanceof FilterableColumn || !$filterable->supportsInlineEdit()) {
            return false;
        }

        $last = $this->state->getFilterableRules()->filter(fn($r) => $r->getKeyReference() === $key)->last();

        if ($last) {
            $this->openEditor($last->getId());
        } else {
            $this->closeEditors();
            $this->state->addRule($filterable->getRuleInstance(['value' => null])->setKeyReference($key));
        }
    }

    public function cleanSearch()
    {
        $this->mutate(fn($state) => $state->setSearch(null));
    }

    /**
     * Applies a pill editor. The editor is named by its rule's id (inline_{id}): two pills of one filter have their
     * own field (the first rule with the key was updated), and the whole form is posted.
     */
    public function setInlineFilterValue()
    {
        $this->mutate(fn() => $this->applySetInlineFilterValue());
    }

    protected function applySetInlineFilterValue()
    {
        [$id, $rule] = $this->ruleFromRequest();
        $filterable = $rule instanceof FilterableRule ? $rule->getFilterable($this->searchableInstance) : null;

        // Only filters with a pill editor: the others (a scope rule's scope, called on the query) aren't edited here.
        if (!$filterable?->supportsInlineEdit()) {
            return false;
        }

        $raw = Filterable::postedRuleField('inline_', $id);
        $value = $filterable->normalizeInlineValue($raw, method_exists($rule, 'getOperator') ? $rule->getOperator() : null);

        if ($value === null) {
            // Applying an empty editor clears the filter.
            $this->state->removeRule($id);
        } else {
            $rule->setValue($value);
            $this->state->replaceRule($id, $rule->setEditing(false));
        }
    }

    /** ✕ / Esc in a pill editor: an existing pill keeps its value, a new one (never applied) is removed. */
    public function cancelRuleEdit()
    {
        $this->mutate(fn() => $this->applyCancelRuleEdit());
    }

    protected function applyCancelRuleEdit()
    {
        [$id, $rule] = $this->ruleFromRequest();

        if (!$rule instanceof FilterableRule) {
            return false;
        }

        $rule->isPendingValue()
            ? $this->state->removeRule($id)
            : $this->state->replaceRule($id, $rule->setEditing(false));
    }

    public function makeRuleEditable()
    {
        $this->mutate(fn() => $this->applyMakeRuleEditable());
    }

    protected function applyMakeRuleEditable()
    {
        [$id, $rule] = $this->ruleFromRequest();

        if (!$rule instanceof FilterableRule) {
            return false;
        }

        $this->openEditor($id);
    }

    /** One editor at a time: the others are closed (each one focused itself on load, and stayed open). */
    protected function openEditor(string $id)
    {
        $this->closeEditors();

        $rule = $this->state->findRule($id);
        $rule && $this->state->replaceRule($id, $rule->setEditing(true));
    }

    protected function closeEditors()
    {
        $this->state->getFilterableRules()->filter->isEditing()
            ->each(fn($rule) => $this->state->replaceRule($rule->getId(), $rule->setEditing(false)));
    }

    /**
     * [id, rule] of the rule a request is about, [null, null] if none: ?ruleId, a rule of the state with that id and,
     * when ?key is posted, that filter key (a stale or crafted id never reaches another rule, and the key is no
     * fallback). A request without ?ruleId is from a page rendered before ids: ?i (+ ?key), for one release.
     */
    protected function ruleFromRequest(): array
    {
        if (!request()->has('ruleId')) {
            return $this->legacyRuleFromRequest();
        }

        $rule = $this->state->findRule(request('ruleId'));
        $key = request('key');

        if (!$rule || (request()->has('key') && !($rule instanceof FilterableRule && is_string($key) && $rule->getKeyReference() === $key))) {
            return [null, null];
        }

        return [$rule->getId(), $rule];
    }

    /**
     * @deprecated pages rendered before rule ids: ?i, as long as that rule still has the posted ?key (indexes shift
     * when rules change in another tab or request); otherwise the rule with that key. Removed in the next release.
     */
    protected function legacyRuleFromRequest(): array
    {
        if (!request()->has('i')) {
            return [null, null];
        }

        Log::info('searchbar.legacy_index_request', ['route' => request()->route()?->getName()]);

        $i = request('i');
        $key = request('key');
        $rule = is_numeric($i) ? $this->state->getRules()->values()->get((int) $i) : null;

        // With a posted key, ?i must hold a filter with that key (else a premade rule that shifted into ?i matched).
        if (!$rule || ($key && !($rule instanceof FilterableRule && $rule->getKeyReference() === $key))) {
            $rule = $key ? $this->state->getFilterableRules()->first(fn($r) => $r->getKeyReference() === $key) : null;
        }

        return $rule ? [$rule->getId(), $rule] : [null, null];
    }

    public function executeCustomFilterableFunction()
    {
        // A read: the methods that change the rule (setRuleOperator...) mutate it themselves (Filterable::updateRule()).
        $this->readState();

        [$id, $rule] = $this->ruleFromRequest();
        $function = request('function');

        if (!$rule instanceof FilterableRule) {
            return null;
        }

        // Inputs rendered here refresh the komponent the modal was opened for (a komponent id, nothing else).
        if (is_string(request('refresh')) && preg_match('/^[A-Za-z0-9_-]{1,100}$/', request('refresh'))) {
            searchService($this->serviceKey)->setRefreshTarget(request('refresh'));
        }

        return $rule->getFilterable($this->searchableInstance)?->executeCustomMethod($function, $id);
    }
}
