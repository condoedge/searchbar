<?php

namespace Kompo\Searchbar\Components;

use Kompo\Query;
use Kompo\Searchbar\Facades\SearchStateModel;
use Kompo\Searchbar\SearchItems\Stores\DbStore;

class FavoritesSearches extends Query
{
    use SearchStateRequestUtils;
    use SearchKomponentUtils;

    public function noItemsFound()
    {
        return _Html('translate.navbar.no-favorites')->class('mt-2');
    }

    public function top()
    {
        return _Rows(
            _Html('translate.favorites')->icon('filter')->class('text-level1'),
        )->class('mb-4');
    }

    public function query()
    {
        return SearchStateModel::getAllForUser();
    }

    public function render($item)
    {
        return _FlexBetween(
            _Link($item->name)->selfPost('loadFavorite', ['id' => $item->id])->refresh('navbar-search')->class('mb-2'),

            _Link()->icon('trash')->selfPost('deleteFavorite', ['id' => $item->id])->refresh('navbar-search')->class('mb-2'),
        )->class('pr-6');
    }

    public function bottom()
    {
        return _Flex(
            _Link('translate.navbar.new-favorite')->selfPost('getNewFavoriteForm')->inModal()->class('mt-2'),
        );
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