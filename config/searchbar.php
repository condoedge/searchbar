<?php

use Kompo\Searchbar\Models\SearchState;
use Kompo\Searchbar\SearchItems\Stores\SessionStore;

return [
    'store' => SessionStore::class,

    'base_result_table_namespace' => 'App\Kompo\Search',

    'searchstate_model' => SearchState::class,
];