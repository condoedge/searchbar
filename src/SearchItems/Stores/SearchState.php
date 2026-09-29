<?php

namespace Kompo\Searchbar\SearchItems\Stores;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;
use Kompo\Searchbar\Searchable\Searchable;
use Kompo\Searchbar\SearchItems\Rules\PremadeRuleWrapper;
use Kompo\Searchbar\SearchItems\Rules\Rule;
use Kompo\Searchbar\SearchItems\SearchItem;

/**
 * Its rules are addressed by id (Rule::getId()), never by position: positions shifted when rules changed in another
 * tab or request. An id no rule has is a no-op. Int (or digit string) positions are still accepted by replaceRule()
 * and removeRule(), deprecated.
 */
class SearchState extends SearchItem
{
    protected $rules;
    protected $search;
    protected $searchableEntity;
    protected $open = false;
    // A table's header sort of the rows it shows (Kompo's X-Kompo-Sort, recorded by HasSearchbarFilters on each browse),
    // null for none: its export and grouped actions don't carry the header (SearchService::sortedByHeader()).
    protected $sort = null;
    // Stored rules StateCodec couldn't rebuild when this state was read ([key, reason] each): never stored. A reopened
    // state stores itself once without them (cleanForReopen()), instead of dropping (and logging) them on every read.
    protected array $droppedStoredRules = [];

    /** Replaces the rule $id by $rule, which takes that id (a pill edited in place keeps its id). */
    public function replaceRule($id, $rule)
    {
        $id = $this->idOf($id);

        if ($id === null || !$this->findRule($id)) {
            return $this;
        }

        $rule->setId($id);
        $this->rules = $this->rules->map(fn($r) => static::idOfRule($r) === $id ? $rule : $r)->values();

        return $this;
    }

    public function removeRule($id)
    {
        // Strict: a missing id (null, or false from a failed search()) removes nothing (it loosely equaled index 0).
        $id = $this->idOf($id);

        if ($id !== null) {
            $this->rules = $this->rules->reject(fn($r) => static::idOfRule($r) === $id)->values();
        }

        return $this;
    }

    public function addRule($rule)
    {
        $this->rules = $this->collectedRules()->push($this->withOwnId($rule))->values();

        return $this;
    }

    public function addRuleAtFirst($rule)
    {
        $this->rules = $this->collectedRules()->prepend($this->withOwnId($rule))->values();

        return $this;
    }

    /** GETTERS */
    public function getRules()
    {
        $this->ensureIds();

        return $this->rules->map->injectContext($this->searchContextService);
    }

    /** The rule with that id, or null (removed meanwhile, crafted, not an id). */
    public function findRule($id): ?Rule
    {
        if (!Rule::isValidId($id)) {
            return null;
        }

        $this->ensureIds();
        $rule = $this->rules->first(fn($r) => static::idOfRule($r) === $id);

        return $rule && $this->hasContext() ? $rule->injectContext($this->searchContextService) : $rule;
    }

    /** The rules as they are stored (StateCodec::encode()): ids given, no context needed (a state built on its own). */
    public function storedRules(): Collection
    {
        $this->ensureIds();

        return $this->rules->filter(fn($rule) => $rule instanceof Rule)->values();
    }

    /** Stored rules that couldn't be rebuilt when this state was read ([key, reason] each): see StateCodec::decode(). */
    public function droppedStoredRules(): array
    {
        return $this->droppedStoredRules;
    }

    public function setDroppedStoredRules(array $dropped): static
    {
        $this->droppedStoredRules = $dropped;

        return $this;
    }

    public function getFilterableRules()
    {
        return $this->getRules()->filter(fn($r) => $r instanceof FilterableRule);
    }

    public function getDefaultRules()
    {
        return $this->getRules()->filter(fn($r) => $r instanceof PremadeRuleWrapper);
    }

    public function getSearch()
    {
        return $this->search;
    }

    public function getSearchableEntity()
    {
        return $this->searchableEntity;
    }

    public function isOpen()
    {
        return $this->open;
    }

    /** The header sort of the rows a table shows ("column:ASC|other:DESC"), null for none. */
    public function getSort(): ?string
    {
        return is_string($this->sort) && $this->sort !== '' ? $this->sort : null;
    }

    public function getSearchableInstance(): ?Searchable
    {
        if (!$this->searchableEntity) {
            return null;
        }

        return $this->searchableEntity::createWithContext($this->searchContextService);
    }

    /**
     * WAS ENABLED FOR THE NEXT PURPOSE:
     * To have a default entity to show in the results panel even if the user didn't select any, 
     * we needed to use this method to return an instance of that default entity when we don't have any selected searchable entity.
     * 
     * But since it's a weird behaviour i set it as configuration that can be disabled
     * And just being more specific but still abstract, we just call it in results panel
     * but panel doesn't know about the specific implementation
     */
    public function getSearchableInstanceForResultsPanel(): ?Searchable
    {
        $searchableEntity = $this->getSearchableEntity();

        if (config('searchbar.default-results-entity') && !$searchableEntity) {
            $searchableEntity = new (config('searchbar.default-results-entity'));
        }

        if (!$searchableEntity) {
            return null;
        }

        return $searchableEntity::createWithContext($this->searchContextService);
    }

    /** SETTERS */
    public function setRules($rules)
    {
        $this->rules = collect($rules)->values();
        $this->ensureIds();

        return $this;
    }

    public function setSearch($search)
    {
        $this->search = $search;

        return $this;
    }

    public function setSearchableEntity($searchableEntity)
    {
        $this->searchableEntity = $searchableEntity;

        return $this;
    }

    public function setOpen($open)
    {
        $this->open = $open;

        return $this;
    }

    /**
     * Kompo sort values only (columns, relation.column, each with an optional direction, joined by "|"), at most 255
     * characters: it comes from a request header and is stored with the state. Anything else is no sort.
     */
    public function setSort(?string $sort)
    {
        $this->sort = is_string($sort) && strlen($sort) <= 255 && preg_match('/^[\w.]+(:\w+)?(\|[\w.]+(:\w+)?)*$/', $sort) === 1
            ? $sort
            : null;

        return $this;
    }

    /**
     * No search text, no filter pill, and exactly its entity's default premade rules: a table with nothing to reset,
     * an export with no filter to describe. Without entity: true.
     */
    public function isOnDefaults(): bool
    {
        $searchable = $this->getSearchableInstance();

        if (!$searchable) {
            return true;
        }

        if (is_string($this->search) && trim($this->search) !== '') {
            return false;
        }

        $rules = $this->getRules();

        if ($rules->contains(fn($rule) => !$rule instanceof PremadeRuleWrapper)) {
            return false;
        }

        $keys = fn($premades) => collect($premades)->map(fn($rule) => (string) $rule->getKey())->sort()->values()->all();

        return $keys($rules) === $keys($searchable->getDefaultRulesApplied());
    }

    /** REOPENING */
    /**
     * A stored state shown again (a remembered table): no pill left pending or open in an editor (each editor focuses
     * itself on load), and with $searchable, no filter it no longer declares (a later deploy renamed or removed it:
     * its rule would still query its own column, and a failing query broke the table on every visit) and its premade
     * rules rebuilt for today's context (refreshPremadeRules()). Rules keep their ids. Returns whether anything changed
     * (the caller stores the state only then).
     */
    public function cleanForReopen(?Searchable $searchable = null): bool
    {
        $rules = $this->collectedRules();
        $declared = $searchable ? collect($searchable::filterables())->keys()->map(fn($key) => (string) $key)->flip() : null;
        $undeclared = fn($r) => $declared && $r instanceof FilterableRule && !$declared->has((string) $r->getKeyReference());

        if ($declared && ($dropped = $rules->filter($undeclared))->isNotEmpty()) {
            // Owner decision: unknown stored filters are dropped and logged.
            Log::info('searchbar.undeclared_filters_dropped', [
                'entity' => $this->searchableEntity,
                'keys' => $dropped->map(fn($r) => $r->getKeyReference())->values()->all(),
            ]);
        }

        $kept = $rules->reject(fn($r) => ($r instanceof FilterableRule && $r->isPendingValue()) || $undeclared($r))->values();
        $changed = $kept->count() !== $rules->count();

        $kept->each(function ($r) use (&$changed) {
            if ($r instanceof FilterableRule && $r->isEditing()) {
                $r->setEditing(false);
                $changed = true;
            }
        });

        $this->rules = $kept;

        // Rules dropped when it was read (StateCodec): stored without them once, not dropped again on every read. Not
        // for rules that failed to rebuild (StateCodec::failedDrops(): a filterable throwing now): their stored data
        // may be fine, and writing the state would lose it for good.
        if ($this->droppedStoredRules && !StateCodec::failedDrops($this->droppedStoredRules)) {
            $this->droppedStoredRules = [];
            $changed = true;
        }

        // Both run: the premade rules are refreshed whatever the pills did.
        return ($searchable ? $this->refreshPremadeRules($searchable) : false) || $changed;
    }

    /**
     * Premade rules keep the parameters they were built with (SISC Invoice: forTeam(currentTeamId())): a stored one is
     * rebuilt by its key from $searchable, so it applies to the viewer's current team, keeping its id. One the
     * searchable no longer declares is dropped, and so is a second copy of a key. Returns whether anything changed.
     */
    public function refreshPremadeRules(Searchable $searchable): bool
    {
        $changed = false;
        $seen = [];

        $this->rules = $this->collectedRules()->map(function ($rule) use ($searchable, &$changed, &$seen) {
            if (!$rule instanceof PremadeRuleWrapper) {
                return $rule;
            }

            $current = PremadeRuleWrapper::findByKey($searchable, $rule->getKey());

            if (!$current || isset($seen[$rule->getKey()])) {
                $changed = true;

                return null;
            }

            $seen[$rule->getKey()] = true;

            if ($current->sameDefinitionAs($rule)) {
                return $rule;
            }

            $changed = true;

            return $current->setId($rule->getId());
        })->filter()->values();

        $this->ensureIds();

        return $changed;
    }

    /** HELPERS */
    /**
     * The whole working state as plain data (StateCodec, format v2): pending pills, open editors, the header sort and
     * the open flag included (the session, remembered tables, a results page's link).
     */
    public function toStorageArray()
    {
        return StateCodec::encode($this);
    }

    /**
     * The rows a table shows, as toArray() plus its header sort: a table's export, rebuilt later from it (a queued
     * export runs in a worker, maybe after the user changed the filters). Not for favorites or copies: the sort is
     * the table's view, and a navbar state with one would search strictly.
     */
    public function toSnapshotArray(): array
    {
        return $this->toArray() + ['sort' => $this->getSort()];
    }

    /**
     * Plain data (StateCodec, format v2) for favorites, shared views, recent searches and the "Open in a table" link:
     * a pill still pending or being edited is UI state, and would open (and grab the focus) as an editor wherever the
     * state is restored. Without the header sort (see toSnapshotArray()).
     */
    public function toArray()
    {
        return StateCodec::encode($this, true);
    }

    /** RULE IDS */
    /** @deprecated a position (int or digit string): the id of the rule there. Otherwise a valid id, or null. */
    protected function idOf($idOrIndex): ?string
    {
        if (is_int($idOrIndex) || (is_string($idOrIndex) && ctype_digit($idOrIndex))) {
            $this->ensureIds();

            return static::idOfRule($this->rules->get((int) $idOrIndex));
        }

        return Rule::isValidId($idOrIndex) ? $idOrIndex : null;
    }

    protected function collectedRules(): Collection
    {
        return $this->rules instanceof Collection ? $this->rules : collect($this->rules ?? [])->values();
    }

    /** A rule entering the state keeps its id unless it has none or the state already has it (a posted or copied rule). */
    protected function withOwnId(Rule $rule): Rule
    {
        $this->ensureIds();
        $taken = $this->collectedRules()->map(fn($r) => static::idOfRule($r))->filter()->flip();

        while (!Rule::isValidId($rule->getId()) || $taken->has($rule->getId())) {
            $rule->setId(Rule::newId());
        }

        return $rule;
    }

    /**
     * Gives an id to every rule without one (or sharing the id of an earlier rule):
     * - a rule unserialized without id (a session state, link or favorite stored before ids): 'l{position}', the same
     *   on every read of that stored state until it is stored again with the ids;
     * - any other (built in this request: default rules of a reset, an entity's initial rules): a random id, so the
     *   id of a pill still showing in another tab never names the rule that took its place.
     */
    protected function ensureIds(): void
    {
        // Positions from 0 (a state stored with a filtered collection kept its keys).
        $this->rules = $this->collectedRules()->values();
        $taken = [];
        $missing = [];

        foreach ($this->rules->values() as $position => $rule) {
            // Not a rule (an incomplete class of a stored state): nothing to address.
            if (!$rule instanceof Rule) {
                continue;
            }

            $id = $rule->getId();

            if (Rule::isValidId($id) && !isset($taken[$id])) {
                $taken[$id] = true;
            } else {
                $missing[$position] = $rule;
            }
        }

        foreach ($missing as $position => $rule) {
            $legacy = $rule->wasUnserializedWithoutId();
            $id = $legacy ? 'l' . $position : Rule::newId();

            for ($n = 1; isset($taken[$id]); $n++) {
                $id = $legacy ? 'l' . $position . 'x' . $n : Rule::newId();
            }

            $rule->setId($id);
            $taken[$id] = true;
        }
    }

    protected static function idOfRule($rule): ?string
    {
        return $rule instanceof Rule ? $rule->getId() : null;
    }
}