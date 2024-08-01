<?php

namespace Kompo\Searchbar\Facades;

use Illuminate\Support\Facades\Facade;
use Kompo\Auth\Facades\FacadeUtils;

/**
 * @mixin \Kompo\Searchbar\Models\SearchState
 */
class SearchStateModel extends Facade
{
    use FacadeUtils;
    
    public static function getFacadeAccessor()
    {
        return 'search-state-model';
    }
}