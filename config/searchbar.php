<?php

use Kompo\Searchbar\Models\SearchState;
use Kompo\Searchbar\SearchItems\Stores\LinkStore;
use Kompo\Searchbar\SearchItems\Stores\SessionStore;

return [
    // The navbar's store. SessionStore: the session (every request writes the whole session back when it ends, so one
    // that overlapped a change can revert it). DatabaseStore: search_states rows of type WORKING for signed-in users,
    // each change a read-modify-write under the row's lock (guests keep the session).
    'store' => SessionStore::class,
    // The navbar's working states (DatabaseStore) not changed for this many days are deleted (searchbar:prune-links);
    // remembered tables keep table-lifetime-days.
    'working-lifetime-days' => 8,
    // Working states per user besides remembered tables (one per page display that changed its search): writing one
    // more deletes the user's least recently changed.
    'max-working-states' => 100,

    // Stores of specific search services: the "Open in a table" page (service searchTable) is a saved link.
    'service-stores' => [
        'searchTable' => LinkStore::class,
    ],

    // Links not changed for this many days are deleted (searchbar:prune-links, scheduled daily).
    'link-lifetime-days' => 10,

    // Tables (HasSearchbarFilters) remember each signed-in user's filters and search text, per table: one search_states
    // row per user and table (TableStore). false: a table opens on its defaults again after a reload.
    'remember-tables' => true,
    // Remembered tables not used for this many days are deleted (searchbar:prune-links).
    'table-lifetime-days' => 180,
    // Remembered tables per user: creating one more deletes the user's least recently used.
    'max-remembered-tables' => 200,
    // Largest stored state (JSON, format v2) written to or read from a search_states row (remembered table, link,
    // shared view, favorite, recent search), in KB: a larger one is not written (logged as searchbar.state_too_large),
    // and a larger stored one is refused when read (searchbar.stored_state_refused). A few pills take a few hundred bytes.
    'max-state-kb' => 256,
    // Longest search text kept (navbar and tables), in characters.
    'max-search-length' => 255,

    // The user's last navbar searches, listed on the empty panel (RecentSearches: search_states rows of type RECENT).
    // At most this many per user, the oldest dropped; searchbar:prune-links trims them daily. 0 turns them off.
    'recent-searches' => 8,

    // A table's "Filter" menu shows the option chips of a section (entity options, select scopes) only up to this many
    // options; 0 shows none.
    'table-menu-section-max-options' => 20,

    'base_result_table_namespace' => 'App\Kompo\Search',

    'searchstate_model' => SearchState::class,

    'max-count-searchable' => 100,

    // Navbar panel: letters or digits of at least one typed word before it counts and lists results ("m", "ma",
    // "j-p" were LIKE scans of whole tables, 2.3-3.8 s per keystroke). 0 turns the gate off; ngram / CJK hosts: 2 or
    // lower. Tables and "Search by" chips aren't gated.
    'search-min-chars' => 3,

    // Options a relation select loads at most when it does not search on the server (plus the selected ones): a whole
    // people table (100k+) was loaded on each pill edit. Above every real option list (SISC: under 1000).
    'max-relation-options' => 2000,

    // Relation filters with more records than this search their options on the server as the user types (Kompo AJAX
    // select), when the relation can be searched: RelationEntityType::searchOn(), a scopeSearchAsRelation($query, $search)
    // on the model, or a Searchable model whose base filter is a text column (full-text, ranked as the navbar).
    'relation-search-threshold' => 500,
    // Characters of at least one typed word before such a select searches (shorter words still narrow the search).
    'relation-search-min-chars' => 3,
    // Options one search returns.
    'relation-search-limit' => 50,

    'fulltext' => [
        // Shorter words are LIKE word starts, not MATCH (a shorter prefix matches most of the index). At least the
        // server's innodb_ft_min_token_size.
        'min-token' => 3,
        // Longer words are LIKE word starts: MATCH silently drops them. null = the server's innodb_ft_max_token_size
        // (read once a day per connection).
        'max-token' => null,
    ],

    // A class with __invoke($searchable, string $action): bool replacing the grouped actions' permission check (a
    // class string: survives config:cache).
    'grouped-actions-gate' => null,
    // Most checked rows one grouped action takes; above it the selection is refused as a whole.
    'grouped-actions-max-ids' => 500,

    // Builder macros a scope rule may call besides local scopes: only macros that read (soft deletes also register
    // restore(), which writes). Anything else fails closed.
    'scope-macros' => ['withTrashed', 'onlyTrashed', 'withoutTrashed'],

    // Middleware of the searchstate/* routes.
    'route-middleware' => ['web', 'auth'],
];
