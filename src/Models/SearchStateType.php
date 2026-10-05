<?php

namespace Kompo\Searchbar\Models;

enum SearchStateType: int {
    case GLOBAL = 1;
    case USER = 2;
    // "Open in a table" pages (?link=token): not favorites, pruned by searchbar:prune-links.
    case LINK = 3;
    // Working states kept in the DB (remembered table filters, navbar state): not favorites.
    case WORKING = 4;
    // A user's recent navbar searches (RecentSearches: the latest searchbar.recent-searches per user): not favorites.
    case RECENT = 5;
}
