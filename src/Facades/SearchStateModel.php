<?php

namespace Kompo\Searchbar\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @mixin \Kompo\Searchbar\Models\SearchState
 */
class SearchStateModel extends Facade
{
    use \Condoedge\Utils\Facades\FacadeUtils;
    
    public static function getFacadeAccessor()
    {
        return 'search-state-model';
    }
}