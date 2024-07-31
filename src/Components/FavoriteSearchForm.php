<?php

namespace Kompo\Searchbar\Components;

use Kompo\Auth\Common\Modal;
use Kompo\Searchbar\SearchItems\Stores\DbStore;

class FavoriteSearchForm extends Modal
{
    use SearchKomponentUtils;

    protected $_Title = 'translate.generic.favorite-search';
    protected $noHeaderButtons = true;

    public function handle()
    {
        $dbStore = DbStore::createWithContext($this->searchService, request('name'));

        $dbStore->storeState($this->state);
    }

    public function body()
    {
        return _Rows(
            _Input('translate.name')->name('name'),

            _FlexEnd(
                _SubmitButton('generic.save')->onSuccess(fn($e) => $e->refresh('navbar-search')->refresh('custom-filters-modal')->closeModal()),
            ),
        );
    }
}