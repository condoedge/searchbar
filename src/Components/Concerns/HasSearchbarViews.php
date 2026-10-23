<?php

namespace Kompo\Searchbar\Components\Concerns;

use Illuminate\Support\Facades\Log;
use Kompo\Core\KompoAction;
use Kompo\Searchbar\Components\FavoriteSearchForm;
use Kompo\Searchbar\Components\ShareSearchViewModal;
use Kompo\Searchbar\Facades\SearchStateModel;
use Kompo\Searchbar\SearchItems\Stores\LinkStore;
use Kompo\Searchbar\SearchItems\Stores\SearchStore;

/**
 * A table's views (part of HasSearchbarFilters): its "Views" menu saves the current filters as a favorite, loads one of
 * the user's favorites of the table's entity (own and global), and shares the view.
 *
 * Sharing gives the page URL with ?searchbar_link={token}: a LINK snapshot of the filters and search text (LinkStore::
 * snapshot(), named after the table's class), never changed afterwards. Opening that URL (anyone signed in with the
 * link) replaces the viewer's own view of this table with a copy, cleaned as a reopened state (premade rules rebuilt
 * for the viewer's current team); the page then takes the parameter out of the address bar, so a reload keeps the
 * viewer's later edits. Each opening touches the link: shared views still in use aren't pruned.
 * Defines no created(): bootSearchbar() calls searchbarOpenSharedLink().
 */
trait HasSearchbarViews
{
    // A shared view of this table was in the page URL (opened, or no longer decoding): searchbarFilters() takes
    // ?searchbar_link out of the address bar.
    protected bool $searchbarOpenedLink = false;

    /** False: no "Views" menu (no favorites, no sharing) for this table. */
    protected function searchbarHasViews(): bool
    {
        return true;
    }

    /**
     * The page URL of this table opening a copy of its current view (a new snapshot link, or the same one when this
     * view was shared already), or null when it can't be shared from here. The page is the one the request came from
     * (the Referer of the table's Kompo request), on this host only. AbstractResultsTable shares its own link.
     */
    protected function searchbarShareUrl(): ?string
    {
        $page = $this->searchbarSameHostReferer();

        if (!$page || !$this->state->getSearchableEntity()) {
            return null;
        }

        $token = LinkStore::snapshot($this->state, LinkStore::tableLinkName($this->searchbarTableRef()));

        return $token ? static::searchbarUrlWithParam($page, 'searchbar_link', $token) : null;
    }

    public function getSearchbarShareModal()
    {
        return new ShareSearchViewModal(['url' => $this->searchbarShareUrl()]);
    }

    public function getSearchbarFavoriteForm()
    {
        return $this->instanciateSearchKomponent(FavoriteSearchForm::class, [
            'serviceKey' => $this->getServiceKey(),
            'refresh_id' => $this->id,
        ]);
    }

    /**
     * The shared view this table is opened with (?searchbar_link= in the page URL; for a table loaded by a Kompo request,
     * in its page's URL, the Referer), copied into the table's state: the user's remembered view of the table, replaced.
     * Only a view shared from this table class, of $entity. Returns whether one was opened (a view that no longer
     * decodes isn't: the viewer's own view stays).
     */
    protected function searchbarOpenSharedLink(?string $entity): bool
    {
        // Signed-in viewers, as the menu that shares it: a guest on a public page keeps the table's own view.
        if (!auth()->check()) {
            return false;
        }

        $token = request('searchbar_link');

        if (!is_string($token) && KompoAction::header()) {
            $token = static::searchbarQueryParam($this->searchbarSameHostReferer(), 'searchbar_link');
        }

        $link = $entity && is_string($token) ? LinkStore::findLink($token) : null;

        if (!$link || $link->name !== LinkStore::tableLinkName($this->searchbarTableRef())) {
            return false;
        }

        $data = SearchStore::decodeRow($link);

        if (!$data || ($data['entity'] ?? null) !== $entity) {
            return false;
        }

        $store = $this->searchService->getStore();

        // Rebuilt through the entity's current filterables (a rule that no longer fits is dropped, logged). One that
        // isn't a state any more, or fails to rebuild: the viewer keeps their own view instead of the page failing.
        // Still taken out of the address bar: every reload would try it again.
        $state = rescue(function () use ($store, $data) {
            $state = $store->stateFromArray($data)?->setSort(null);
            $state?->cleanForReopen($state->getSearchableInstance());

            return $state;
        }, null, true);

        if (!$state) {
            Log::warning('searchbar.shared_view_undecodable', ['link' => $link->id, 'table' => static::class]);

            $this->searchbarOpenedLink = true;

            return false;
        }

        // In place of the viewer's state, under its lock as any change (SearchStore::mutate()).
        $this->state = $store->mutate(fn() => $state);
        $this->searchableInstance = $this->state->getSearchableInstanceForResultsPanel();

        LinkStore::touchLink($link);

        return $this->searchbarOpenedLink = true;
    }

    /** Takes ?searchbar_link out of the address bar once the view is opened: a reload keeps the viewer's later edits. */
    protected function searchbarForgetLinkInUrl()
    {
        if (!$this->searchbarOpenedLink) {
            return null;
        }

        return _Hidden()->name('searchbar_link_clean', false)->onLoad(fn($e) => $e->run('() => {'
            . ' const url = new URL(window.location.href);'
            . ' if (url.searchParams.has("searchbar_link")) { url.searchParams.delete("searchbar_link");'
            . ' history.replaceState(history.state, "", url.toString()); } }'));
    }

    /**
     * The user's favorites of this table's entity, own and global, the 10 most recent (of the 50 most recent overall).
     * Only their entity is read (not their rules): nothing is instantiated.
     */
    protected function searchbarFavorites()
    {
        $entity = $this->state->getSearchableEntity();

        if (!$entity || !auth()->check()) {
            return collect();
        }

        return SearchStateModel::getAllForUser()->latest('id')->limit(50)->get(['id', 'name', 'type', 'user_id', 'raw_state'])
            ->filter(fn($favorite) => (SearchStore::decodeRow($favorite, false)['entity'] ?? null) === $entity)
            ->take(10)->values();
    }

    /**
     * "Views": save the filters as a favorite, the favorites of this entity (a click loads one into the table: its
     * filters and search text replace the table's), and "Share this view…". Signed-in users only.
     */
    protected function searchbarViewsMenu()
    {
        if (!$this->searchbarHasViews() || !auth()->check() || !$this->state->getSearchableEntity()) {
            return null;
        }

        // Not rendered by a browse of this table (each keystroke in its search box, a page, a header sort) nor by its
        // export: Kompo runs top() then only to prepare its fields (QueryFilters::prepareFiltersForAction()), and this
        // menu has none. Its favorites query ran for nothing. A table built while another komponent's browse renders
        // its rows is displayed: it keeps its menu.
        if ($this->searchbarExporting() || (KompoAction::is('browse-items') && $this->searchbarIsRequestTarget())) {
            return null;
        }

        $favorites =$this->searchbarFavorites()->map(fn($favorite) => $this->searchbarAction(
            // Names are user text and labels render as HTML: escaped (a global favorite is shown to every user).
            _DropdownLink(e($favorite->name))->icon(_Sax('star', 14))->class('py-2 px-3 truncate max-w-xs searchbar-view-favorite')
                ->title((string) $favorite->name),
            'searchstate.load-favorite', ['id' => $favorite->id],
        ));

        return _Dropdown('filter.views')->icon(_Sax('star', 16))->button()->alignRight()->submenu(
            ...collect([
                _DropdownLink('filter.new-favorite')->icon(_Sax('add', 16))
                    ->class('py-2 px-3 border-b border-gray-200 font-medium searchbar-for-' . $this->searchbarScopeClass())
                    ->selfPost('getSearchbarFavoriteForm')->inModal(),
            ])->concat($favorites->isNotEmpty() ? $favorites : [_Html('filter.no-favorites')->class('px-3 py-2 text-xs text-gray-500')])
                ->push(_DropdownLink(__('filter.share-view') . '…')->icon(_Sax('share', 16))
                    ->class('py-2 px-3 border-t border-gray-200 searchbar-for-' . $this->searchbarScopeClass())
                    ->selfPost('getSearchbarShareModal')->inModal())
                ->all(),
        );
    }

    /** The Referer when it is a page of this host (a Kompo request's page), else null. */
    protected function searchbarSameHostReferer(): ?string
    {
        $referer = request()->headers->get('referer');

        return is_string($referer) && $referer !== '' && parse_url($referer, PHP_URL_HOST) === request()->getHost()
            ? $referer
            : null;
    }

    /** A query parameter of $url, as a string (null when missing or not a string). */
    protected static function searchbarQueryParam(?string $url, string $name): ?string
    {
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        return is_string($query[$name] ?? null) ? $query[$name] : null;
    }

    /**
     * $url with $name=$value instead of any $name it had. Its other parameters are kept as written (parse_str() would
     * rename "a.b" to "a_b"), and so is its fragment.
     */
    protected static function searchbarUrlWithParam(string $url, string $name, string $value): string
    {
        [$url, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        [$path, $query] = array_pad(explode('?', $url, 2), 2, '');

        $pairs = collect($query === '' ? [] : explode('&', $query))
            ->reject(fn($pair) => $pair === '' || urldecode(explode('=', $pair, 2)[0]) === $name)
            ->push(rawurlencode($name) . '=' . rawurlencode($value));

        return $path . '?' . $pairs->implode('&') . ($fragment !== null ? '#' . $fragment : '');
    }
}
