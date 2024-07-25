<?php

use Kompo\Searchbar\SearchService;
use Kompo\Searchbar\SearchItems\Stores\SearchStore;

function searchService($key = SearchService::DEFAULT_KEY): SearchService
{
    $keyService = 'searchService.' . $key;

    if (!app()->has($keyService)) {
        app()->singleton($keyService, function() use($key) {
            return new SearchService($key);
        });
    }

    return app($keyService);
}

function stateStore($key = SearchService::DEFAULT_KEY): SearchStore
{
    return searchService($key)->getStore();
}