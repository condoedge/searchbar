<?php

namespace Kompo\Searchbar\Components;

use Condoedge\Utils\Kompo\Common\Query;
use Kompo\Searchbar\Facades\SearchStateModel;
use Kompo\Searchbar\SearchItems\Stores\DbStore;

class FavoritesSearches extends Query
{
    use SearchStateRequestUtils;
    use SearchKomponentUtils;

    public $hasPagination = false;

    public $itemsWrapperClass = '[&>div]:flex [&>div]:flex-wrap [&>div]:gap-2';

    public function noItemsFound()
    {
        return _Html('filter.no-favorites')->class('text-sm text-gray-400');
    }

    public function top()
    {
        return _Flex(
            _Html('filter.favorites')->icon('star')->class('font-semibold text-level1 text-lg'),
        )->class('mb-3');
    }

    public function query()
    {
        return SearchStateModel::getAllForUser();
    }

    public function render($item)
    {
        return _FlexBetween(
            _Link($item->name)->selfPost('loadFavorite', ['id' => $item->id])->refresh('navbar-search')->class('text-sm truncate'),
            _Link()->icon('trash')->selfPost('deleteFavorite', ['id' => $item->id])->refresh('navbar-search')->class('text-gray-400 hover:text-red-500 shrink-0'),
        )->class('bg-level4 rounded-lg px-3 py-2 border border-level4 hover:border-greenmain gap-2 min-w-0');
    }

    public function bottom()
    {
        return _Flex(
            _Link('filter.new-favorite')->icon(_Sax('add', 16))->selfPost('getNewFavoriteForm')->inModal()->class('text-sm text-greenmain font-medium hover:underline'),
        )->class('mt-2');
    }

    public function deleteFavorite()
    {
        $store = DbStore::createWithContext($this->searchService, request('id'));
        $store->clearState();
    }

    public function loadFavorite()
    {
        $store = DbStore::createWithContext($this->searchService, request('id'));
        $state = $store->getState();
        $state->setOpen(true);

        $this->searchService->getStore()->storeState($state);
    }

    public function getNewFavoriteForm()
    {
        return $this->instanciateSearchKomponent(FavoriteSearchForm::class);
    }
}