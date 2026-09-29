<?php

namespace Kompo\Searchbar\Components;

use Condoedge\Utils\Kompo\Common\Form;
use Kompo\Core\KompoInfo;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;
use Kompo\Searchbar\SearchItems\Stores\LinkStore;
use Kompo\Searchbar\SearchItems\Stores\RecentSearches;
use Kompo\Searchbar\SearchItems\Stores\StateCodec;
use Kompo\Searchbar\SearchItems\Stores\TableStore;
use Kompo\Searchbar\SearchService;

/**
 * The "Open in a table" page, at /search-results?link={token}: its state is a saved link (LinkStore), so the URL
 * stays short, reloads keep the edits and it can be shared. Its filters are edited in the results table
 * (AbstractResultsTable / HasSearchbarFilters): search box, "+ Filter" and pills.
 *
 * Opened with:
 * - ?link=: a saved link; the author keeps editing it, anyone else gets a copy (what was shared doesn't change under them);
 * - ?from=: the navbar search of this session (its store key), saved as a new link (and one of the user's recent
 *   searches: RecentSearches);
 * - ?searchDetails=: the older signed links.
 */
class SearchResults extends Form
{
    const SEARCH_ID = 'searchTable';
    const ID = 'search-results';

    public $id = self::ID;
    public $containerClass = 'container-table';

    protected $state;
    protected $storeKey;
    protected $searchService;
    protected bool $firstDisplay = false;

    public function created()
    {
        // created() runs on every boot (the page, then each refresh): only the page load opens the link, the kompo
        // requests reuse its token from their encrypted boot info. Not from the page URL: its query string fills the
        // store too, and ?storeKey=<token chosen by someone else> would make this page write to their link.
        $this->firstDisplay = !request()->hasHeader(KompoInfo::$key) || !$this->prop('storeKey')
            || !$this->ownsLink($this->prop('storeKey'));
        $this->storeKey = $this->firstDisplay ? $this->openLink() : $this->prop('storeKey');

        if ($this->firstDisplay) {
            $this->store(['storeKey' => $this->storeKey]);
        }

        $this->searchService = searchService(self::SEARCH_ID)->setStoreKey($this->storeKey);
        $this->state = $this->searchService->getStore()->getState();
    }

    /**
     * Also checked on refreshes: this page only ever edits a link of its user (a pruned one opens empty), never a table's
     * shared view (a snapshot: its author gets a copy too).
     */
    protected function ownsLink($token): bool
    {
        $link = LinkStore::findLink($token);

        return !$link || ((int) $link->user_id === (int) auth()->id() && !LinkStore::isSnapshot($link));
    }

    /** The token of the link this page shows and edits. */
    protected function openLink(): string
    {
        $link = LinkStore::findLink(request('link'));

        // Opened: kept by searchbar:prune-links while used, not only while edited (as a table's shared view).
        if ($link) {
            LinkStore::touchLink($link);
        }

        if ($link && (int) $link->user_id === (int) auth()->id() && !LinkStore::isSnapshot($link)) {
            $this->storeWithoutDroppedRules($link->token);

            return $link->token;
        }

        $token = LinkStore::newToken();
        $store = searchService(self::SEARCH_ID)->setStoreKey($token)->getStore();

        if ($link) {
            // Rebuilt through the entity's current filterables (any format; a rule that no longer fits is dropped). A
            // link that isn't a state any more or fails to rebuild: an empty copy (then the default entity) instead of
            // the page failing.
            rescue(fn() => $store->setFromCollection(StateCodec::toV2((string) $link->raw_state) ?? []), fn() => $store->setFromCollection([]), true);
        } elseif (is_string(request('from')) && request('from') !== '') {
            // A separate service instance: setting the key on the navbar's singleton would switch the navbar's state.
            $navbarState = (new SearchService(SearchService::DEFAULT_KEY))->setStoreKey(request('from'))->getStore()->getState();
            $store->setFromCollection(collect($navbarState->toArray()));

            // "Open in a table" uses the navbar's search: one of the user's recent searches (not a remembered table's
            // state, which a crafted ?from= could name).
            if (!TableStore::handles(request('from'))) {
                RecentSearches::remember($navbarState);
            }
        } elseif (request('searchDetails')) {
            $store->setFromRequest('searchDetails');
        }

        $this->prepareStateForEditing($store);

        return $token;
    }

    /**
     * The author's own link, read with rules its filters no longer fit (dropped, logged: StateCodec): stored without
     * them once, instead of dropping them again on every request of the page.
     */
    protected function storeWithoutDroppedRules(string $token): void
    {
        $store = searchService(self::SEARCH_ID)->setStoreKey($token)->getStore();

        if ($store->getState()->droppedStoredRules()) {
            // Read again under the link's lock: an edit of the page in another tab stays.
            $store->mutate(fn($state) => $state->droppedStoredRules() ? $state->setDroppedStoredRules([]) : false);
        }
    }

    /**
     * Without an explicit entity, the default entity and its default rules become explicit stored rules (the
     * table edits them like the others). Pending pills and open editors of the navbar are dropped. The query stays
     * the same; the search text stays the search (the table's search box).
     */
    protected function prepareStateForEditing($store)
    {
        $store->mutate(function ($state) {
            $searchable = $state->getSearchableInstanceForResultsPanel();

            if ($searchable && !$state->getSearchableEntity()) {
                $state->setRules($searchable->getDefaultRulesApplied()->values());
                $state->setSearchableEntity(get_class($searchable));
            }

            $state->setRules($state->getRules()
                ->reject(fn($rule) => $rule instanceof FilterableRule && $rule->isPendingValue())
                ->each(fn($rule) => $rule instanceof FilterableRule ? $rule->setEditing(false) : null)
                ->values());

            // A new view: not the header sort recorded on the link it copies (its author's table).
            $state->setSort(null);
        });
    }

    public function render()
    {
        $typeInstance = $this->state->getSearchableInstanceForResultsPanel();

        return _Rows(
            $this->firstDisplay ? $this->showLinkInUrl() : null,

            _Html(__('filter.search-results.with-values', ['entity' => $typeInstance?->searchableName()]))
                ->class('text-2xl sm:text-3xl font-bold text-greenmain mb-4'),

            !$typeInstance ? _Html('navbar.no-results')->class('text-gray-500') : _Rows(
                $typeInstance->getTableClassInstance([
                    'storeKey' => $this->storeKey,
                ]),
            )->id('search-results-table'),
        );
    }

    /** ?link={token} in the address bar (from ?from= / a copy): reloading, bookmarking or sharing opens this page. */
    protected function showLinkInUrl()
    {
        if (request('link') === $this->storeKey || !\Route::has('search.results')) {
            return null;
        }

        $url = route('search.results', ['link' => $this->storeKey]);

        return _Hidden()->name('search_results_url', false)->onLoad(fn($e) => $e->run('() => { history.replaceState(history.state, "", '
            . json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) . '); }'));
    }
}
