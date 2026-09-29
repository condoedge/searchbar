<?php

namespace Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\EntityType;

use Illuminate\Database\Eloquent\Relations\Relation;
use Kompo\Searchbar\Searchable\RelationSearchable;
use Kompo\Searchbar\Searchable\Searchable;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumn;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumnTypeEnum;
use Kompo\Searchbar\SearchService;

class RelationEntityType extends EntityType
{
    protected $relation;
    protected $baseQuery;

    /** @var null|string[]|\Closure */
    protected $searchOn = null;

    public function __construct($relation, string $baseQuery, bool $allowAllOption = false)
    {
        if (!is_subclass_of($relation, RelationSearchable::class)) {
            throw new \Exception('Relation must implement RelationSearchable interface');
        }

        $this->relation = $relation;
        $this->baseQuery = $baseQuery;
        $this->allowAllOption = $allowAllOption;
    }

    /**
     * How the select finds records when it searches on the server (lists over searchbar.relation-search-threshold):
     * columns LIKE-matched (each typed word in one of them), or fn($query, string $search) returning the query.
     * Without it: the model's scopeSearchAsRelation($query, $search), else a Searchable's text base filter (full-text,
     * ranked as the navbar; the base query's own order breaks ties). Else the list stays loaded (max-relation-options).
     */
    public function searchOn(array|\Closure $columnsOrCallback): static
    {
        $this->searchOn = $columnsOrCallback;

        return $this;
    }

    protected function baseQueryBuilder()
    {
        $baseQuery = $this->baseQuery;

        return $this->relation::$baseQuery();
    }

    public function optionsWithLabels()
    {
        $baseQuery = $this->baseQuery;

        return $this->addAllOption($this->relation::parseOptions($this->relation::$baseQuery()->get()));
    }

    /**
     * Inputs render on every pill edit and navbar refresh: the whole table (100k+ people for an "owner" filter)
     * was loaded and labelled each time. Capped by searchbar.max-relation-options, plus the selected values.
     * (Lists that search on the server send only their selected values: selectedOptions().)
     */
    public function optionsForInput(array $selected = [])
    {
        $records = $this->baseQueryBuilder()->limit((int) config('searchbar.max-relation-options', 2000))->get();
        $missing = $this->realValues($selected)->diff($records->modelKeys());

        if ($missing->isNotEmpty()) {
            $records = $records->concat($this->relation::query()->whereKey($missing->all())->get());
        }

        return $this->addAllOption($this->labelled($records));
    }

    // SERVER SEARCH

    public function canSearchOnServer(): bool
    {
        return $this->searchOn !== null
            || $this->baseQueryBuilder()->getModel()->hasNamedScope('searchAsRelation')
            || $this->searchableTextFilterKey() !== null;
    }

    /** A list that can be searched, with more records than searchbar.relation-search-threshold. */
    public function searchesOnServer(): bool
    {
        if (!$this->canSearchOnServer()) {
            return false;
        }

        $threshold = max(0, (int) config('searchbar.relation-search-threshold', 500));
        $memoKey = "searchbar.relation-over-threshold.{$this->relation}@{$this->baseQuery}#{$threshold}";
        // Once per request (a pill editor and a modal row share it). Not across requests: base scopes can depend on
        // the user's team.
        $memo = request()->attributes;

        if (!$memo->has($memoKey)) {
            $memo->set($memoKey, $this->countAtMost($threshold + 1) > $threshold);
        }

        return (bool) $memo->get($memoKey);
    }

    public function selectedOptions(array $selected = [])
    {
        $ids = $this->realValues($selected);
        // Not the base query: a selected record out of it (an inactive team) still shows its label.
        $options = $this->labelled($ids->isEmpty() ? collect() : $this->relation::query()->whereKey($ids->all())->get());

        // A value without a visible record (deleted, out of scope) keeps a tag: a value missing from the options
        // became `false` in the multiselect (vue-kompo FieldSelect::getOptionFromValue).
        foreach ($ids as $id) {
            if (!$options->has($id)) {
                $options->put($id, e((string) $id));
            }
        }

        return $this->addAllOption($options);
    }

    public function searchOptions(?string $search)
    {
        $search = trim((string) $search);
        $limit = max(1, (int) config('searchbar.relation-search-limit', 50));

        $options = $this->labelled($this->searchQuery($search)->limit($limit)->get());

        return $search === '' ? $this->addAllOption($options) : $options;
    }

    /** The base query narrowed to $search (see searchOn()), in a stable order. */
    public function searchQuery(string $search)
    {
        $query = $this->baseQueryBuilder();
        $search = trim($search);

        // No text (relation-search-min-chars 0): the first records as the base query gives them. Ordering every
        // record to show a few took ~1s on the people (a sort of the whole active-teams semi-join).
        if ($search === '') {
            return $query;
        }

        $query = match (true) {
            $this->searchOn instanceof \Closure => ($this->searchOn)($query, $search) ?? $query,
            is_array($this->searchOn) => $this->likeSearch($query, $this->searchOn, $search),
            $query->getModel()->hasNamedScope('searchAsRelation') => $query->searchAsRelation($search),
            default => $this->searchableTextFilterQuery($query, $search),
        };

        // Unique last key: equal relevances (every "Martin") come back in a stable order.
        return $query->orderBy($query->getModel()->getQualifiedKeyName());
    }

    /** Each typed word (at most 5) in one of $columns; wildcards typed are literal. Read in the columns' order. */
    protected function likeSearch($query, array $columns, string $search)
    {
        foreach (collect(preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY))->take(5) as $word) {
            $like = '%' . addcslashes($word, '\\%_') . '%';

            $query->where(function ($q) use ($columns, $like) {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'like', $like);
                }
            });
        }

        // The base query's own order wins; else by the searched columns (not by id).
        if (!$query->toBase()->orders) {
            foreach ($columns as $column) {
                $query->orderBy($column);
            }
        }

        return $query;
    }

    /** The base filter of a Searchable relation when it takes free text (a TEXT column: Person/Team name_filter). */
    protected function searchableTextFilterKey(): ?string
    {
        $relation = $this->relation;

        if (!is_subclass_of($relation, Searchable::class) || !method_exists($relation, 'getBaseFilterable')) {
            return null;
        }

        $key = $relation::getBaseFilterable();
        $filterable = $relation::filterables()[$key] ?? null;

        return $filterable instanceof FilterableColumn && $filterable->getInputType() === FilterableColumnTypeEnum::TEXT ? $key : null;
    }

    /**
     * The relation's text base filter applied as the navbar does (full-text relevance, rows with every word first).
     * The base query's own order only breaks ties: before the relevance (a base ordered by name), the options were the
     * first matches of any word by name and records with every typed word fell out of the limit.
     */
    protected function searchableTextFilterQuery($query, string $search)
    {
        if (!$key = $this->searchableTextFilterKey()) {
            return $query->whereRaw('1 = 0');
        }

        // The builder itself, not toBase(): with global scopes (HasSecurity) that's a copy.
        $baseOf = fn($q) => $q instanceof Relation ? $q->getBaseQuery() : $q->getQuery();
        $base = $baseOf($query);
        [$baseOrders, $baseOrderBindings] = [$base->orders ?? [], $base->bindings['order'] ?? []];
        $base->orders = null;
        $base->bindings['order'] = [];

        // Its own context: the navbar's singleton searchService() keeps its store key.
        $context = new SearchService('relation-options');
        $searchable = $this->relation::createWithContext($context);

        $query = $searchable->filterable($key)->getRuleInstance(['value' => $search])
            ->setKeyReference($key)->injectContext($context)->setSearchable($searchable)
            ->query($query);

        // After the rule's orders, with their bindings (compiled in the same sequence).
        $base = $baseOf($query);
        $base->orders = array_merge($base->orders ?? [], $baseOrders) ?: null;
        $base->bindings['order'] = array_merge($base->bindings['order'] ?? [], $baseOrderBindings);

        return $query;
    }

    /**
     * Rows of the base query, counted up to $max: keys only, no ORDER BY (a full count of the people took ~600ms,
     * this ~15ms). Same shape as SearchService::countableQuery().
     */
    protected function countAtMost(int $max): int
    {
        $query = $this->baseQueryBuilder();
        $base = $query->toBase();

        $keys = ($base->groups || $base->havings || $base->distinct || $base->unions)
            ? $base->cloneWithout(['orders'])->cloneWithoutBindings(['order'])
            : $base->cloneWithout(['columns', 'orders'])->cloneWithoutBindings(['select', 'order'])
                ->select($query->getModel()->getQualifiedKeyName());

        // newQuery(): the relation's own connection (TrainingMoodle is on "moodle"; DB::query() is the default one).
        return $base->newQuery()->fromSub($keys->limit($max), 'searchbar_relation_size')->count();
    }

    /** Option labels render as HTML (vue-kompo): record names are escaped. */
    protected function labelled($records)
    {
        return collect($this->relation::parseOptions($records))->map(fn($label) => is_string($label) ? e($label) : $label);
    }

    /** Selected record keys: no "all" option, blanks or crafted arrays. */
    protected function realValues(array $values)
    {
        return collect($values)->flatten()->reject(fn($v) => !is_scalar($v) || $v === 'all' || $v === '')->unique()->values();
    }

    public function getValue()
    {
        return $this->relation;
    }

    public function from($value)
    {
        return $this->relation::find($value);
    }

    public function getLabel($value)
    {
        return $this->parseLabelWithAllOption($value, fn() => $this->from($value)?->label() ?? null);
    }

    public function getLabels(array $values)
    {
        $records = $this->relation::query()->whereKey(collect($values)->reject(fn($v) => $v === 'all')->values()->all())->get()->keyBy(fn($r) => $r->getKey());

        return collect($values)->mapWithKeys(fn($value) => [
            $value => $this->parseLabelWithAllOption($value, fn() => $records->get($value)?->label()),
        ]);
    }
}
