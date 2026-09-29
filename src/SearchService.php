<?php

namespace Kompo\Searchbar;

use Kompo\Core\KompoAction;
use Kompo\Searchbar\Components\SearchbarIds;
use Kompo\Searchbar\Searchable\Searchable;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;
use Kompo\Searchbar\SearchItems\Stores\SearchState;
use Kompo\Searchbar\SearchItems\Stores\SearchStore;
use Kompo\Searchbar\SearchItems\Stores\SessionStore;
use Kompo\Searchbar\SearchItems\Stores\TableStore;

class SearchService
{
    const DEFAULT_KEY = 'default';

    // Where a header sort taken over by a table (takeOverHeaderSort()) stays readable for the rest of the request.
    const HEADER_SORT_ATTRIBUTE = 'searchbar.header-sort';

    // What a state change changed: which navbar komponents it refreshes (refreshTargets()).
    /** A pill, option chip, toggle, custom filters row or rule form: the rules. */
    const CHANGE_RULES = 'rules';
    /** A "Search by" chip: the rules, and it may consume the typed text. */
    const CHANGE_TEXT_TO_RULE = 'chip';
    /** An entity picked or cleared (picking one clears the typed text). */
    const CHANGE_ENTITY = 'entity';
    /** A favorite replaced the entity, the rules and the text. */
    const CHANGE_ALL = 'all';

    /**
     * @var \Kompo\Searchbar\Searchable\Searchable[]
     * @property \Kompo\Searchbar\Searchable\Searchable[] $searchables
     */
    protected $searchables = [];
    protected $key;
    protected $store;
    protected $storeKey;
    // The komponent every change refreshes (a table), null for the navbar (refreshTargets() picks its parts).
    protected ?string $refreshTarget = null;
    protected bool $strictFullText = false;
    // The request that drew the current store key (isFreshStoreKey()), null for a key given.
    protected ?\WeakReference $freshKeyRequest = null;

    public function __construct($key = self::DEFAULT_KEY)
    {
        $this->key = $key;

        $this->setStoreKey();
    }

    // A signed chip rule and a queued export's table serialize their service: not the WeakReference (it can't be).
    public function __sleep()
    {
        return array_keys(array_diff_key(get_object_vars($this), ['freshKeyRequest' => true]));
    }

    // SETTINGS
    /**
     * @param \Kompo\Searchbar\Searchable\Searchable[] $searchables
     * @return void
     */
    public function setSearchables($searchables)
    {
        collect($searchables)->each(function($searchable, $key) {
            if (!in_array(Searchable::class, class_implements($searchable))) abort(500, __('crm.searchable-not-implemented', ['searchable' => $key]));
        });

        $this->searchables = $searchables;    
    }

    /**
     * Summary of getSearchables
     * @return \Illuminate\Support\Collection<\Kompo\Searchbar\Searchable\Searchable>
     */
    public function getSearchables()
    {
        return collect($this->searchables);
    }

    public function optionsSearchables()
    {
        $search = $this->getStore()->getState()->getSearch();
        // Nothing typed (blank included) or too short to search: no count query, every count is "?".
        $counted = static::searchText($search) !== '' && !static::textTooShort($search);

        return $this->getSearchables()->mapWithKeys(function($searchable) use($counted) {
            $count = $counted ? $this->getCountSpecificType($searchable) : '?';
            $hasResults = !in_array((string) $count, ['0', '?'], true);

            return [
                $searchable => _FlexBetween(
                    _Html($searchable::searchableName())->class('text-sm font-medium'),
                    _Rows(
                        _Html($count)->class('entityCountPill'),
                        _Spinner('w-3 h-3', 'text-gray-600')->class('relative p-1 hidden searchbar-loading'),
                    )->class('py-px px-2 text-xs font-semibold rounded-full')
                        // White on bg-warning was ~1.8:1 contrast; searchbarLoadingOff() swaps these same pairs.
                        ->class($hasResults ? 'bg-greenlight text-greendark' : 'bg-graylight text-graydark'),
                // A keyboard item of the navbar panel (searchbarClientJs()).
                )->class('searchbar-nav-item gap-4 cursor-pointer rounded-lg mx-2 px-3 py-1.5 min-w-48 transition-colors hover:bg-level4')
                // The panel refresh re-renders this list too: a second refresh() of it was a wasted request.
                ->onClick(fn($e) => $e->run('() => { window.searchbarBusy && searchbarBusy(); }')
                    && $e->post('searchstate.select-entity', $this->stateParams(['searchableEntity' => $searchable]))
                        ->withAllFormValues()->refresh($this->refreshTargets(self::CHANGE_ENTITY)),
                ),
            ];
        });
    }

    /**
     * Letters or digits of at least one typed word before the navbar panel counts and lists results
     * (searchbar.search-min-chars). A host whose full-text filters use ngram (CJK: two-character words are normal and
     * cheap there) sets 2 or lower.
     */
    public static function minSearchChars(): int
    {
        return max(0, (int) config('searchbar.search-min-chars', 3));
    }

    /**
     * Text is typed but none of its words has search-min-chars letters or digits: the navbar panel shows a hint
     * instead of counting and listing. "m", "ma" or "j-p" is a LIKE scan of the 341k people, matching thousands
     * (the results column took 2.3-3.8 s per keystroke, 0.9-1.9 s of it counting). Tables and "Search by" chips
     * aren't gated.
     *
     * @param mixed $search the text to check, else this service's stored search: a komponent holding the state passes
     *                      getSearch() ?? '' (null would read the store again)
     */
    public function searchTextTooShort($search = null): bool
    {
        return static::textTooShort($search ?? $this->getStore()->getState()->getSearch());
    }

    /**
     * No word of $text has $min (default search-min-chars) letters or digits. An empty text isn't too short.
     * One word, as the relation selects (relation-search-min-chars), not the whole text: "a b" was no cheaper than "a".
     * But words split as the full-text search splits them (letters and digits), not on spaces only: "j-p" is the
     * words "j" and "p", 2.3 s like "m". The relation selects can share this rule by passing their minimum.
     */
    public static function textTooShort($text, ?int $min = null): bool
    {
        $min = max(0, $min ?? static::minSearchChars());
        $text = static::searchText($text);

        return $min > 0 && $text !== ''
            && !collect(preg_split('/[^\pL\pM\pN]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [])->contains(fn($word) => mb_strlen($word) >= $min);
    }

    /**
     * Posted search text as it is kept: a string of at most searchbar.max-search-length (255) characters, else null.
     * A remembered table stores it for months.
     */
    public static function capSearchText($search): ?string
    {
        return is_string($search) ? mb_substr($search, 0, max(1, (int) config('searchbar.max-search-length', 255))) : null;
    }

    /** A stored search as trimmed text (anything else is no text). */
    protected static function searchText($search): string
    {
        return is_scalar($search) ? trim((string) $search) : '';
    }

    //QUERIES
    /** @param mixed $baseQuery a table's own query to filter (HasSearchbarFilters), else the searchable's baseSearchQuery() */
    public function getQuery($baseQuery = null)
    {
        $state = $this->getStore()->getState();
        $searchableEntity = $state->getSearchableInstanceForResultsPanel();

        if(!$searchableEntity) {
            return null;
        }

        $rules = $this->getQueryRules();
        $baseQuery = $baseQuery ?? $searchableEntity::baseSearchQuery();

        // Rows sorted by the query's own order (a table's) or by a header sort can't show the best matches first: the
        // relevance orders come after it, and any-word matches ("Martin Garrix": every Martin) were interleaved by date.
        // There the full-text rules require every word. Only while these rules apply (counts reuse the rules).
        $query = $this->withStrictFullText(
            static::hasOwnOrders($baseQuery) || static::sortedByHeader($state),
            fn() => $rules->reduce(fn($query, $rule) => $rule->query($query), $baseQuery),
        );

        // Unique last key: tied rows otherwise repeat or vanish across LIMIT/OFFSET pages and export chunks.
        return $query->orderBy($query->getModel()->getQualifiedKeyName())->with($searchableEntity->getEagerRelationsKeys());
    }

    /** True while getQuery() applies the rules of a sorted query: full-text rules then require every word, unranked. */
    public function isStrictFullText(): bool
    {
        return $this->strictFullText;
    }

    /** Applies rules through $apply with full-text strictness $strict, then restores the previous one. */
    protected function withStrictFullText(bool $strict, callable $apply)
    {
        $previous = $this->strictFullText;
        $this->strictFullText = $strict;

        try {
            return $apply();
        } finally {
            $this->strictFullText = $previous;
        }
    }

    /** The query has its own ORDER BY. */
    protected static function hasOwnOrders($query): bool
    {
        $base = match (true) {
            $query instanceof \Illuminate\Database\Eloquent\Relations\Relation => $query->getBaseQuery(),
            $query instanceof \Illuminate\Database\Eloquent\Builder => $query->getQuery(),
            default => $query,
        };

        return !empty($base?->orders);
    }

    /**
     * The rows are sorted by a table header (Kompo's X-Kompo-Sort: applied after the query is built, it replaces every
     * order; a HasSearchbarFilters table applies it itself, see takeOverHeaderSort()). A browse of the rows carries the
     * table's current sort ('' for none). The table's other requests (its
     * export, a grouped action, the queued export's worker) don't: they read the sort the table recorded in its state
     * on its last browse or display (HasSearchbarFilters), so they get the rows it shows, as strict.
     */
    protected static function sortedByHeader(?SearchState $state = null): bool
    {
        $header = static::headerSort();

        if ($header !== '' || KompoAction::is('browse-items')) {
            return $header !== '';
        }

        return $state?->getSort() !== null;
    }

    /** The header sort of this request (Kompo's X-Kompo-Sort, '' for none), also once a table took it over. */
    public static function headerSort(): string
    {
        $header = (string) request()->header('X-Kompo-Sort');

        if ($header !== '') {
            return $header;
        }

        // Only this request's own: Kompo's browse-many sub-requests are clones of the previous one (its attributes
        // included), each with its own headers.
        $taken = request()->attributes->get(static::HEADER_SORT_ATTRIBUTE);
        $headers = is_array($taken) ? ($taken['headers'] ?? null) : null;

        return $headers instanceof \WeakReference && $headers->get() === request()->headers ? (string) ($taken['sort'] ?? '') : '';
    }

    /**
     * A table sorted the browsed rows itself, its unique key last (HasSearchbarColumnHeaders::searchbarHeaderSorted()):
     * Kompo must not sort them again (DatabaseQuery::handleSort() clears every ORDER BY, the tie-break included). The
     * header leaves the request; headerSort() still reads it (the strict full-text, the recorded sort).
     */
    public static function takeOverHeaderSort(): void
    {
        request()->attributes->set(static::HEADER_SORT_ATTRIBUTE, ['sort' => static::headerSort(), 'headers' => \WeakReference::create(request()->headers)]);
        request()->headers->remove('X-Kompo-Sort');
    }

    public function getQueryRules()
    {
        $state = $this->getStore()->getState();

        $searchableEntity = $state->getSearchableInstanceForResultsPanel();

        if(!$searchableEntity) {
            return null;
        }

        if ($state->getSearchableEntity()) {
            // Explicit entity: user-configured rules don't include the default
            // full-text rule, so append it (a no-op pending rule when there is no search text).
            $rules = $state->getRules()->values();
            $rules->push($searchableEntity->defaultFilterRule());
        } else {
            // Initial rules already include the default full-text rule (pushed
            // by getInitialRules when a search term is present); pushing again
            // would duplicate the relevance selectRaw and break the SQL.
            $rules = $searchableEntity->getInitialRules($searchableEntity);
        }

        return $rules;
    }

    public function getCountSpecificType($type)
    {
        $model = $type::createWithContext($this);

        $rules = $model->getInitialRules($model);

        if(!$rules) {
            return '?';
        }

        $baseQuery = $model->baseSearchQuery();

        // Counts the rows the results panel lists: getQuery() on a baseSearchQuery() with its own order requires every
        // word (the header sort is left out: the entity list is the navbar's, never header-sorted).
        $query = $this->withStrictFullText(static::hasOwnOrders($baseQuery), fn() => collect($rules)->reduce(function($query, $rule) use ($model) {
            if ($rule instanceof FilterableRule) {
                $rule->setSearchable($model);
            }

            return $rule->query($query);
        }, $baseQuery));

        return $this->cappedCount($query);
    }

    /**
     * Counts at most max-count-searchable rows (+1 to know there are more): "N", or "max+".
     * Only which rows match matters here, so the counted subquery drops the full-text relevance column and the
     * ORDER BYs: they made every keystroke rank all the matches just to count them.
     */
    public function cappedCount($query)
    {
        $max = (int) config('searchbar.max-count-searchable', 100);

        // fromSub keeps the subquery bindings as "from" bindings: count() drops "select" ones, which broke the
        // count as soon as the relevance column had a bound value. On the query's own connection (DB::query() was the
        // default one).
        $countable = $this->countableQuery($query)->limit($max + 1);
        $count = $countable->newQuery()->fromSub($countable, 'sub')->count();

        return $count > $max ? $max . '+' : $count;
    }

    /**
     * Every matching row counted ("Open in a table"). Laravel's pagination count drops the relevance column, its
     * binding and the ORDER BYs, and only wraps the query when it must (groups, havings): a derived table was ~5x slower.
     */
    public function fullCount($query)
    {
        return ($query instanceof \Illuminate\Database\Eloquent\Builder ? $query->toBase() : $query)->getCountForPagination();
    }

    protected function countableQuery($query)
    {
        $base = $query instanceof \Illuminate\Database\Eloquent\Builder ? $query->toBase() : $query;

        // Their columns decide what a row is: kept (only the ORDER BYs go).
        if ($base->groups || $base->havings || $base->distinct || $base->unions) {
            return $base->cloneWithout(['orders'])->cloneWithoutBindings(['order']);
        }

        $trimmed = $base->cloneWithout(['columns', 'orders'])->cloneWithoutBindings(['select', 'order']);

        // table.* rather than 1: MariaDB picked a much slower plan for "select 1" in the limited derived table.
        return is_string($base->from) && !str_contains($base->from, ' ')
            ? $trimmed->select($base->from . '.*')
            : $trimmed->selectRaw('1');
    }

    // STATES
    public function setStoreKey($key = null): SearchService
    {
        $generated = $key === null;

        // Random, not time(): two komponents of one service booted in the same second shared one state.
        $key = $key ?? ($this->key . '-' . \Illuminate\Support\Str::random(12));

        // The cached store is bound to the previous key. The same key given again (a komponent booting from the props
        // this service just drew) keeps it, fresh or not.
        if ($key !== $this->storeKey) {
            $this->store = null;
            $this->freshKeyRequest = $generated ? \WeakReference::create(request()) : null;
        }

        $this->storeKey = $key;

        return $this;
    }

    /**
     * The store key was drawn in this request (a page display): nothing can be stored under it yet but by this
     * request, so a database store doesn't look for its row (DatabaseStore). Only for the request that drew it: this
     * service is a singleton, which outlives a request in tests and long-lived workers.
     */
    public function isFreshStoreKey(): bool
    {
        return $this->freshKeyRequest?->get() === request();
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * The komponent every change of this state refreshes: a table (HasSearchbarFilters, or the custom filters modal
     * and rule forms opened by one), else null (or the navbar's own id): the navbar, whose parts refreshTargets() picks.
     */
    public function setRefreshTarget(?string $komponentId): SearchService
    {
        $this->refreshTarget = $komponentId && $komponentId !== SearchbarIds::NAVBAR ? $komponentId : null;

        return $this;
    }

    /** The table this state's changes refresh, null for the navbar. */
    public function refreshTargetId(): ?string
    {
        return $this->refreshTarget;
    }

    /**
     * The komponents to refresh after a change of this state, in one refresh action (Kompo batches them into one
     * refresh-many request): a table's whole komponent, or the navbar parts that $change touches. Refreshing the whole
     * navbar re-rendered the input (focus and typed text lost), the panel (skeleton, then its results loaded again)
     * and every chip for a pill change. Kompo skips the listed komponents it doesn't list as live: the results before
     * they lazy-load, and once the panel closed (SearchPanel's searchbarParkResults(): an unmounted komponent stays
     * live for Kompo, and kept answering the refreshes).
     */
    public function refreshTargets(string $change = self::CHANGE_RULES): array
    {
        if ($this->refreshTarget) {
            return [$this->refreshTarget];
        }

        return match ($change) {
            self::CHANGE_ALL => [SearchbarIds::NAVBAR],
            // The panel changes entity (its results, chips, toggles): refreshed whole, skeleton first.
            self::CHANGE_ENTITY => [SearchbarIds::INPUT, SearchbarIds::PILLS, SearchbarIds::PANEL],
            self::CHANGE_TEXT_TO_RULE => [SearchbarIds::INPUT, SearchbarIds::PILLS, SearchbarIds::FILTERS, SearchbarIds::RESULTS],
            default => [SearchbarIds::PILLS, SearchbarIds::FILTERS, SearchbarIds::RESULTS],
        };
    }

    /** @deprecated refreshTargets(): the navbar's id refreshed the whole navbar. */
    public function getRefreshTarget(): string
    {
        return $this->refreshTarget ?? SearchbarIds::NAVBAR;
    }

    /** Route parameters that point a searchstate/* request at this service's state, whichever form sends it. */
    public function stateParams(array $params = []): array
    {
        return array_merge(['serviceKey' => $this->key, 'storeKey' => $this->storeKey], $params);
    }

    public function getStore(): SearchStore
    {
        if(!$this->store) {
            // A remembered table's key (tbl.…) is that user's row, whatever the service: searchstate/* requests carry only
            // the service and store keys. A ses.… key is in the session (a table that doesn't remember). Else a service
            // can have its own kind of store (searchbar.service-stores: the results page uses links).
            $storeClass = match (true) {
                TableStore::handles($this->storeKey) => TableStore::class,
                SessionStore::handles($this->storeKey) => SessionStore::class,
                default => config('searchbar.service-stores', [])[$this->key] ?? null,
            };

            $this->store = $storeClass
                ? (new $storeClass($this->storeKey))->injectContext($this)
                : app(SearchStore::class, ['key' => $this->storeKey, 'contextService' => $this]);
        }

        return $this->store;
    }

    /**
     * Replaces the store for the rest of the request (bound to this service): a state held elsewhere than its key says,
     * e.g. an in-memory copy. A later setStoreKey() with another key drops it.
     */
    public function setStore(SearchStore $store): static
    {
        $this->store = $store->injectContext($this);

        return $this;
    }

    public function getStoreKey(): ?string
    {
        return $this->storeKey;
    }
}