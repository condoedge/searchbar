<?php

namespace Kompo\Searchbar\Models;

enum SearchStateType: int {
    case GLOBAL = 1;
    case USER = 2;
}