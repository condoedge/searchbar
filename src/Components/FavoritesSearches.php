<?php

namespace Kompo\Searchbar\Components;

use Condoedge\Utils\Kompo\Common\Query;
use Illuminate\Support\Facades\Log;
use Kompo\Searchbar\Facades\SearchStateModel;
use Kompo\Searchbar\Models\SearchStateType;
use Kompo\Searchbar\SearchItems\Stores\DbStore;
use Kompo\Searchbar\SearchService;

class FavoritesSearches extends Query
{
    use SearchStateRequestUtils;
    use SearchKomponentUtils;

    // FavoriteSearchForm refreshes this list by it.
    public $id = SearchbarIds::FAVORITES;

    public $hasPagination = false;

    public $itemsWrapperClass = '[&>div]:flex [&>div]:flex-wrap [&>div]:gap-2';

    /** What a favorite is, not only "No favorites": bottom()'s "save the current search" link is the way to add one. */
    public function noItemsFound()
    {
        return _Rows(
            _Sax('star', 28)->class('text-greenmain opacity-60 mb-2'),
            _Html('filter.favorites-empty-title')->class('font-semibold text-greenmain'),
            _Html('filter.favorites-empty-hint')->class('text-sm text-gray-500'),
        )->class('searchbar-favorites-empty items-center text-center bg-level5 bg-opacity-40 rounded-2xl p-6 m-2');
    }

    public function top()
    {
        return null;
    }

    public function query()
    {
        return SearchStateModel::getAllForUser();
    }

    public function render($item)
    {
        // Names are user text and links render as HTML: escaped (a global favorite is shown to every user). The name
        // and "new favorite" are keyboard items of the panel (searchbar-nav-item: searchbarClientJs()).
        return _FlexBetween(
            _Link(e($item->name))->icon(_Sax('star', 14))->class('searchbar-nav-item text-sm truncate max-w-[12rem]')->title((string) $item->name)
                // It replaces the entity, the rules and the text: the whole navbar.
                ->onClick(fn($e) => $e->run('() => { window.searchActiveTab = "filters"; window.searchbarBusy && searchbarBusy(); }')
                    && $e->selfPost('loadFavorite', ['id' => $item->id])->refresh($this->searchService->refreshTargets(SearchService::CHANGE_ALL))),
            !$this->canDelete($item) ? null : _Link()->icon('trash')->selfPost('deleteFavorite', ['id' => $item->id])
                // Only this list changes.
                ->refresh()->class('text-gray-400 hover:text-danger shrink-0'),
        )->class('bg-level4 rounded-lg px-3 py-2 border border-level4 hover:border-greenmain gap-2 min-w-0');
    }

    public function bottom()
    {
        return _Flex(
            _Link('filter.new-favorite')->icon(_Sax('add', 16))->selfPost('getNewFavoriteForm')->inModal()->class('searchbar-nav-item text-sm text-greenmain font-medium hover:underline'),
        )->class('mt-2');
    }

    /** Own favorites only: the id is posted by the browser (any user's, or a shared global one, was deletable). */
    public function deleteFavorite()
    {
        $favorite = SearchStateModel::where('type', SearchStateType::USER)->where('user_id', auth()->id())->find(request('id'));

        if (!$favorite) {
            return;
        }

        DbStore::createWithContext($this->searchService, $favorite->id)->clearState();
    }

    /** One of the favorites listed to this user (own or global), not any id. */
    public function loadFavorite()
    {
        $favorite = SearchStateModel::getAllForUser()->find(request('id'));

        if (!$favorite) {
            return;
        }

        // Rebuilt through its entity's current filterables (a rule that no longer fits is dropped, logged). One that
        // isn't a state any more (its entity no longer a searchable): the navbar keeps its search (and is refreshed,
        // unlocking it) instead of a 500. DbStore throws then: an empty state would wipe the navbar's.
        $state = rescue(fn() => DbStore::createWithContext($this->searchService, $favorite->id)->getState(), null, true);

        if (!$state) {
            Log::warning('searchbar.favorite_undecodable', ['favorite' => $favorite->id]);

            return;
        }

        $state->setOpen(true);

        // In place of the navbar's state, under its lock as any change (SearchStore::mutate()).
        $this->searchService->getStore()->mutate(fn() => $state);
    }

    protected function canDelete($item): bool
    {
        return $item->type === SearchStateType::USER && (int) $item->user_id === (int) auth()->id();
    }

    public function getNewFavoriteForm()
    {
        return $this->instanciateSearchKomponent(FavoriteSearchForm::class);
    }
}