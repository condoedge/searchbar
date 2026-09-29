# searchbar

Search bar, filter pills and result tables for Kompo apps (`condoedge/searchbar`).

## Setup

- Register the searchable models (they implement `Searchable` and use `SearchableModelUtils`):
  `searchService()->setSearchables([Person::class, Team::class, ...]);`
- Mount the navbar search: `$this->instanciateSearchKomponent(NavbarSearch::class)`.
- Declare the "Open in a table" page: `Route::get('search-results', SearchResults::class)->name('search.results');`
  and one results table per searchable, `App\Kompo\Search\Search{Model}Table extends AbstractResultsTable`.
- Run the migrations. The `searchbar:prune-links` command is scheduled daily: it deletes unused links, the navbar's
  working states (with `DatabaseStore`) unchanged for `working-lifetime-days`, the remembered filters of tables unused
  for months, and each user's recent searches beyond `recent-searches` (see below). Then run
  `php artisan searchbar:migrate-states --dry-run`, review it, and run it for real (see "Stored states").

## Filters

A searchable declares `filterables()` (columns, scopes, multi-column text), `sections()` (the chips of the search
panel) and `premadeRules()` (default rules and toggles). Every applied filter is a pill:

- click a pill to edit it (the editor follows the filter's operator: a range for "between", a multi-select for
  "in"...); **Enter** / clicking away applies, **Esc** cancels, **✕** removes;
- a "Search by" chip uses the text typed in the search bar as that field's filter when it fits the field
  (any text for a text field, `5`, `5-10` or `>= 5` for a number, a year or a date for a date, an option label for a
  select); otherwise it opens the pill's editor.

**Rule ids.** Every rule of a state has a stable id (`Rule::getId()`, `r` + 8 hex characters). Pills, pill editors
(`inline_{id}`), custom filters rows (`value_{id}`, `operator_{id}`, `param_{id}`, `columns_{id}`, panel
`input-panel-{id}`) and every `searchstate/*` request name their rule by `?ruleId`, plus its filter `?key`. An unknown
id, or an id whose rule has another key, changes nothing (a stale pill never hits another rule). Rules stored before
ids get `l0`, `l1`… until the state is stored again.

- `SearchState::findRule($id)`, `replaceRule($id, $rule)`, `removeRule($id)`; int positions are deprecated, and so
  are the `?i` requests of pages rendered before the update (served one release, logged as
  `searchbar.legacy_index_request`) and the `searchstate.add-rule` route.
- A host `Filterable::formRow($rule, $ruleId)` receives the rule id: name its fields `{prefix}_{ruleId}`, post
  `$this->ruleRowParams($ruleId, [...])` and read them with `Filterable::postedRuleField($prefix, $ruleId)`.
- A host `Rule` subclass must not declare its own `$id` property (it clashes with the typed one).

**DATE filters** compare whole days with bounds on the bare column (`>= day` and `< next day`), not
`DATE_FORMAT(column)`, so the column's index is used. They work on DATE, DATETIME and TIMESTAMP columns, `RAW::`
columns and `FilterableRawColumn` expressions.

- A value that isn't a date (`Y-m-d`, optionally followed by a time) matches nothing, whatever the operator. So does
  a range bound that isn't a date. A missing bound leaves that side of the range open; reversed bounds are swapped.
- A DATE column written as `relation.column` (nested: `a.b.column`) filters through `whereHas` on Eloquent queries.
  "Different from" becomes `whereDoesntHave` with that day, like the relation rules, so records without a related row
  are kept. A `table.column` naming the query's own or a joined table stays a qualified column.

## Full-text search

A text filter on a column with a FULLTEXT index searches each typed word as a word start.

- **Navbar and unsorted tables**: any word matches, and rows with every word come first (`relevance_all`, then
  `relevance`, then the model's `scopeOrderSearchResults()`, else the shortest text).
- **Sorted tables** (the query given to `searchbarQuery()` or `baseSearchQuery()` has its own `orderBy`, or the user
  sorted by a column header): every word is required and the table's order is kept.
- Words the index can't hold are matched with LIKE as word starts (at the start of the text, or after a space or one
  of `- ' ’ ( . / , " «`): words shorter than `fulltext.min-token`, and words longer than the server's
  `innodb_ft_max_token_size` (MATCH silently drops them). The token sizes are read on the `mysql` and `mariadb`
  drivers and cached a day per connection (`searchbar.fulltext-token-sizes.<connection>`).
- A long word typed alone is a LIKE scan (SISC, 341k persons: about 0.3 s to count, 0.7–0.9 s for the first page).
  With `innodb_ft_max_token_size=10` a lone prefix of a long word ("archamb") still finds almost nothing: set it to
  `84`, rebuild the FULLTEXT indexes, then clear the cached token sizes (or wait a day).

## Phones, addresses and accents

Two opt-ins on a `FilterableColumn`, for local and relation (`relation.column`) columns:

- `->searchDigitsOnly(?string $countryCode = null, int $nationalLength = 10)`: the text operators (contains, equals
  and their negations) compare digits only, on both sides (`REGEXP_REPLACE(col, '[^0-9]', '')` on MySQL, MariaDB and
  PostgreSQL; nested `REPLACE`s of ` -.()+/` elsewhere). "(514) 555-1234", "514.555.1234" and "5145551234" find the
  same number however it is stored. With a country code, "+1 514 555-1234" also finds the national "5145551234", and
  "equals 5145551234" finds "+15145551234". A value without any digit is compared as typed; the pill shows what was
  typed. No index serves it (users type inner digits): on SISC's 369k phones it adds about 30-70 ms to the LIKE scan
  (~300 ms).
- `->fullTextSearch()` on a relation column: "contains" is a MATCH inside the relation's EXISTS, every word required
  (a filter, nothing is ranked); words the index can't hold are LIKE word starts, as above. "Does not contain" is
  `whereDoesntHave` with the same condition. It needs a FULLTEXT index on exactly that column, checked once per process
  (`SHOW INDEX`): without one, and for a value without a word ("#"), the filter stays LIKE `%...%`, so the code can ship
  before the host's migration.
  - With an index holding whole words (`innodb_ft_max_token_size` 84) each indexed word goes through it (SISC
    "rue dufferin": 24 ms instead of 430 ms). With a smaller one (10) each word is MATCH or LIKE word starts, about the
    cost of the former LIKE, so the start of a long word ("maisonneu") is still found.
  - "Contains" becomes word starts: "ufferin" no longer finds "Dufferin".
- Accents and case follow the column's collation: `*_ci` columns (every SISC text filter) already ignore both, in LIKE
  and MATCH. Only binary expressions need a `COLLATE` (`JSON_UNQUOTE`, which `FilterableTranslatableColumn` handles;
  `_bin` / `_as_cs` columns; CASTs): a relation column can use `RAW::relation.column COLLATE ...`.

SISC: Person `phone_filter` is `->searchDigitsOnly('1')` and `address_filter` is `->fullTextSearch()` (index
`ft_addresses_address1`, migration `2026_09_27_140000`; it rebuilds `addresses`: run it off-hours).

## Relation filters

A relation select (`RelationEntityType`) whose base query has more than `relation-search-threshold` records searches
its options on the server as the user types (Kompo AJAX select), when the relation can be searched:

- `->searchOn(['col', ...])`: each typed word LIKE-matched in one of the columns (base query's order, else column
  order);
- `->searchOn(fn ($query, string $search) => ...)`;
- a `scopeSearchAsRelation($query, string $search)` on the model;
- otherwise a `Searchable` model whose base filter is a text column is searched with that filter: full-text, ranked
  like the navbar, the base query's own order only breaking ties.

```php
'owner_filter' => new FilterableColumn('addedBy.person.id', FilterableColumnTypeEnum::RELATION_SELECT,
    (new RelationEntityType(Person::class, 'forSearch'))->searchOn(['first_name', 'last_name'])),
```

- The select's payload names the filter key, never a class; its own options are only the selected values, labelled.
  A search needs one word of at least `relation-search-min-chars` characters and returns `relation-search-limit`
  options. HasSecurity global scopes apply to the search, the size check and the selected labels.
- A relation that can't be searched stays loaded, capped at `max-relation-options` (SISC: Brevet, Decoration).
- A host komponent that renders filter inputs itself must `use SearchKomponentUtils` (it answers
  `searchbarRelationOptions`).
- An option search boots the rendering komponent only to answer it: `NavbarSearch::render()`,
  `HasSearchbarFilters::searchbarFilters()` and `CustomFiltersModal::body()` return early on
  `KompoAction::is('search-options')`, so pills and rows aren't rebuilt.
- The column's relation path must end on the model the options come from: `addedBy.id` is a user id, not a person id
  (`addedBy.person.id`).

## Navbar typing

The typed text is posted from one place, the hidden `#navbar-search-send` link. Typing is debounced 600 ms
(`NavbarSearch::SEARCH_DEBOUNCE_MS`); Enter sends at once and cancels the pending send. Enter on text already sent
posts nothing, unless that send has shown no results for 3 s (treated as failed: it retries). A host overriding the
navbar input keeps `#navbar-search-send` in the input's komponent (`NavbarSearchInput`: the link posts that
komponent's fields) and the input's `navbar-search-input` class (the keyboard navigation finds the input by it), and
calls `searchbarQueueSearch(spinnerId, ms)` on input and `searchbarSendSearch(true, spinnerId)` on Enter.

The panel counts and lists nothing until one typed word has `search-min-chars` (3) letters or digits ("a b", "j-p"
are too short: LIKE scans of whole tables): it says how much to type and every entity count is "?". The rule is
`SearchService::textTooShort($text, ?int $min)`. The results column counts its matches once (the "Open in a table"
number is that page's total).

## Navbar komponents and refreshes

The navbar (`NavbarSearch`, `navbar-search`) is a shell around komponents refreshed apart (ids in `SearchbarIds`):

- `NavbarSearchPills` (`navbar-search-pills`): the entity pill, the rule pills and their editors, and the chip they
  fold into while the panel is closed;
- `NavbarSearchInput` (`navbar-search-box`): the text and the `#navbar-search-send` link;
- `SearchPanel` (`search-panel`): the top bar and the two columns. It holds `SearchFilterOptions`
  (`search-filter-options`: sections, toggles, "Custom filters"; the entity list `SearchableOptions` before an entity
  is picked), `EnhancedSearchbar` (`enhanced-searchbar`: the results, lazy-loaded, with `RecentSearchesList` on top
  of an empty panel) and `FavoritesSearches` (`search-favorites`).

A change refreshes what it touches, in one Kompo refresh-many request, through `SearchService::refreshTargets($change)`:

| Change | Refreshes |
|---|---|
| `CHANGE_RULES`: a pill, an option chip, a default-rule toggle | the pills, the filters column, the results |
| `CHANGE_TEXT_TO_RULE`: a "Search by" chip (it may take the typed text) | the same, plus the input |
| `CHANGE_ENTITY`: an entity picked, Back, the entity pill's ✕ | the input, the pills, the panel |
| `CHANGE_ALL`: a favorite, a recent search | the whole navbar |

- The input keeps its focus and text, and a chip shows no skeleton. A table's state refreshes its own table.
- Never list a komponent with one of its ancestors: the ancestor's refresh replaces it, and its own answer lands on a
  destroyed instance.
- The results column is unmounted when the panel closes and reloads on the next opening (`searchbarParkResults()`), so
  pill changes in the closed navbar don't run the results query.
- While typed text is on its way, the panel's links, the entity rows and the pills stay locked, also across pill,
  filter and entity-count refreshes (`searchbarRelockTyping()`).
- The navbar's forms never submit natively: a capture-phase document listener prevents the submit of any form inside
  `#navbar-search` (in Chrome, Enter in the nested input form reloaded the page).

**Extending the navbar** (a host overriding `NavbarSearch` or one of its komponents, or adding a navbar action):

- Refresh `$this->searchService->refreshTargets(SearchService::CHANGE_…)`, not `navbar-search`: `getRefreshTarget()` is
  deprecated.
- Keep the ids and classes the client code looks for: `#navbar-search-send`, `.navbar-search-input`,
  `.search-rule-pills`, `.searchbar-filters-count`, `.searchbar-nav-item`, `.searchbar-result`, `#search-panel-main`,
  `#search-results-column`, `#search-filters-panel`, `#search-content-*`.
- A host komponent refreshed in the navbar ends its load as the package's do: `searchbarUnlock()` (the change is done),
  `searchbarRelockTyping()` (typed text still on its way), `searchbarKbAfterRefresh()` (focus and outline back), and
  `searchbarSetCompact()` if it renders pills.

## Navbar panel

- **Host contract.** On each open, close and load, `NavbarSearch` dispatches the document event `searchbar:panel` with
  `detail: {open, smallScreen}` (`smallScreen`: below 1024px) and toggles `html.searchbar-panel-open`. The package no
  longer hides host elements by id: a host hides its own navbar items from a listener (SISC:
  `layouts/app-scripts.blade.php`; deploy it with the package).
- **Small screens.** Below md (768px) the panel stacks, the filters under the results: the whole container scrolls
  (white, sticky top bar, `100dvh` where supported), and keeps its scroll across navbar refreshes (chips, toggles,
  pills, Back, favorites) until the panel closes.
- **Pills on phones.** Below md, while the panel is open, the pills get a row of their own, fixed under the navbar
  across the screen (`searchbarPlacePills()`, compiled classes only). The panel starts under it and follows its
  height, and the input keeps the navbar's row (next to the pills it was about 24px wide). The row scrolls sideways
  and scrolls to a pill editor. In the DOM the pills then come after the navbar's row, so the Tab and screen-reader
  order follows the screen (the input, the close ✕, the pills, the panel); a focus inside them is kept when they move.
  Closed, or from md, they are back in the navbar's row, before the input. With that row the panel closes at once
  (fading, it jumped up by the row's height); from md it fades.
- A tab tap or the unfold arrow scrolls to the filters column; a tab tap opens a retracted or folded column, at every
  width. `switchSearchTab(tab, fromUser = false)`: only user taps (`true`) scroll or open the column.
- **Empty states** say what the feature does: favorites (save the current search to reopen it in one click), filters
  ("No filters yet", shown until any rule other than a default one is applied; a quick toggle switched on counts),
  the custom filters modal, and the filters column before an entity is picked.
- **Closed navbar chip.** While the panel is closed, `NavbarSearchPills` folds the pills into one chip
  (`.searchbar-filters-count`): the entity's name (else "Filters") and a green badge counting the applied filters
  (every pill but a pending one, default rules included). Open, the pills are back (`searchbarSetCompact()`, run on
  open, close and each pills load).
  - The server renders the pills shown and the chip hidden, which is what a page without JS shows.
  - A chip click opens the panel through the input's focus and posts nothing.
  - A pill editor is never folded: the pills stay shown until it is done. Keyboard focus on a pill that folds away
    (Esc) moves to the chip.
  - The chip is in the arrow order: ← at the start of the text focuses it, ↓ / → go back to the input (which opens
    the panel); a pills refresh gives the focus back to the new chip.
  - Table pills are never folded.
- **Keyboard.** The input is a combobox (`role=combobox`, `aria-controls="search-panel-container"`, `aria-expanded`
  set on open and close; the retract arrow and the chip carry `aria-expanded` too): the focus stays in the input and the
  active item is outlined (`.searchbar-kb-active`, named by the input's `aria-activedescendant`).
  - ↓ opens a closed panel, then outlines the first item of the results column (a recent search, else a result; the
    first filter while the results load). ↓ / ↑ move within a column (↑ on the first item goes back to the text),
    → / ← go to the filters (or favorites) column / the results column.
  - Enter clicks the outlined item (a result's page or modal, a chip, an entity, a favorite, a recent search) instead
    of searching; a held Enter acts once; without an outline Enter searches as before. A Kompo modal or drawer the
    item opens gets the focus once it shows: a `focusOnLoad()` field, else its first text field (a select's search box
    excepted), else the modal itself, so what is typed next goes there, not to the navbar search.
  - Esc closes the panel, except under a shown Kompo modal or drawer, or while a pill editor is open (Esc cancels the
    editor).
  - Items are the elements with `.searchbar-nav-item`: recent searches, result cards (`.searchbar-result`), entity
    rows, section chips ("Search by" too), "Custom filters", favorites and "Save current search". "Open in a table",
    the default-rule toggles, the tabs, Back and the retract arrow are reached with Tab. An item locked by a running
    change or by typed text on its way (links disabled, entity rows `pointer-events-none`) does nothing on Enter.
  - Typing, the mouse, closing the panel, a tab switch or retracting the filters drop the outline
    (`searchbarKbReset()`). A refresh that took the focus (Enter on a "Search by" chip, an entity, a favorite) gives it
    back to the new input, and a changed chip is outlined again (`searchbarKbAfterRefresh()`, from the navbar
    komponents' loads).
  - From xl (1280px), the top bar shows the keys (`filter.keyboard-hint`); at lg, next to a host sidebar, the line
    was cut off.
  - A host result card opens on its own click: the element `searchElement()` returns carries the `href` or the click.
    A host modal that should focus a field says so with `focusOnLoad()`.

## Recent searches

- A navbar search becomes recent when it is used: Enter, a result opened from the panel (both post
  `searchstate/remember`, a request of its own, since Enter on text already sent posts no search), or "Open in a
  table". A filters-only search counts; typing doesn't. Enter on a text too short to search (`search-min-chars`)
  records nothing ("Open in a table" records it: tables aren't gated).
- They are `search_states` rows of type `RECENT` (5), the latest `recent-searches` (8) per user. The same search
  (entity, text ignoring case and spaces, rules ignoring their ids and order) moves back to the top instead of
  repeating.
- They are listed on top of the panel's results while nothing is typed or filtered (default rules alone and a picked
  entity still show them): `RecentSearchesList`, `#searchbar-recent-searches`. Typing hides the list at once. A row
  puts back the entity, the filters and the text (the whole navbar is refreshed, as for a favorite); ✕ forgets one,
  "Clear" forgets them all. A row's count is the closed navbar chip's (default rules included).
- Only the user's own rows can be restored: a crafted id, or a favorite's or a link's id, changes nothing. A restored
  search is cleaned as a reopened state (no pending pill, undeclared filters dropped, premade rules rebuilt for the
  current team). A row that no longer decodes, or whose entity is no longer registered, is forgotten.
- The filters' descriptions on a row are in the language of the moment the search was used; the entity and the count
  follow the current language.
- `recent-searches = 0` turns the feature off, and the daily prune then deletes every recent search.

## Filters on any table

The search bar engine can filter any Kompo `Table` / `Query` of a searchable model: a search box, a "Filter" menu
(the column filters, short option sections and default rules), a "Views" menu and the same editable pills, on the
table's own state, remembered per user. Its export and grouped actions follow the filters.

```php
use Kompo\Searchbar\Components\HasSearchbarFilters;

class PersonsTable extends Table
{
    use HasSearchbarFilters;

    protected $searchableEntity = Person::class;

    public function query()
    {
        return $this->searchbarQuery(Person::forTeam(currentTeamId())); // or searchbarQuery() for baseSearchQuery()
    }

    public function top()
    {
        return _Rows(
            $this->searchbarFilters(),
            // ...the table's own top
        );
    }
}
```

- A table defining `created()` calls `$this->bootSearchbar()` in it. A subclass inherits its parent's
  `created()` / `bootSearchbar()`: to opt a subclass out (keeping its own `top()` / `query()`), override
  `bootSearchbar()` with an empty body (SISC's `EventTemplateManageList`).
- **On some pages only.** A table shown on several pages boots the searchbar only where it should have it (e.g. from
  a prop its main page passes: props persist in the komponent's boot info). Not booted, the table is untouched:
  `searchbarQuery($base)` returns `$base` as is, `searchbarFilters()` and `searchbarAdvancedFilters()` draw nothing,
  `searchbarTh()` is a plain header, and no state is stored. So the embedding pages keep the table as it was.
- **"Advanced filters".** `searchbarAdvancedFilters()` folds the whole card (search box, Filter and Views menus,
  pills) under a collapsible "Advanced filters (n)" below the table's own controls, which stay as they were (both
  searches apply). It is open while the state is off its defaults, so pills or search text never filter the rows out
  of sight; the box is rendered folded too, so each browse carries its text. SISC shows it on the tables' main pages
  only (members list, teams registry, activities, templates, receivables, the Brevets / Decorations pages).
- The base query passed to `searchbarQuery()` must not have top-level `orWhere`s: wrap them in
  `where(fn ($q) => ...)`, since the state's filters are ANDed after them.
- A table can drop its entity's premade rules with a filters-only subclass of the searchable: `premadeRules()`
  returns `[]`, same permission key and `searchableName()`, not registered in the navbar (SISC's
  `TeamMemberSearchable` for the members lists).
- The table's own `top()` filter fields are reset by pill and Filter actions (they refresh the table).
- The "Filter" menu turns the text typed in the search box into **one more condition** on the chosen field (or opens
  that field's editor when the text can't be one of its values). Conditions on one field AND together (">= 5", then
  "<= 10"); options merge into the field's one IN rule; a condition the field already has is ignored, compared strictly
  ("1e3" is not "1000", 5 and 5.0 are one). The label counts them ("Email (2)"). Its fields are disabled while the box
  is empty: a table never gets an empty filter. The navbar's "Search by" chips still replace their field's filter.
- The menu also shows the searchable's short option sections (entity options, select scopes; at most
  `table-menu-section-max-options` options each): a chip toggles its option, and the options of one field share its IN
  rule. The default rules in the menu are toggles.
- "Custom filters…" (first item of the menu) opens the navbar's custom filters modal on the table's state: every
  filter as a row (field, operator, value of the field's type: dates, numbers, options, scopes, several columns),
  "New filter" for any field, the default rules, and "Reset filters" (back to the table's default rules). It
  refreshes the table and keeps its entity.
- Filter and pill actions refresh the table; the search box (debounced 500 ms) only reloads its rows.
- Each table has its own search service (`table-{class}`) unless it sets `$serviceKey`.
- `AbstractResultsTable` (the "Open in a table" page) is built on it.
- A host `query()` goes through `searchbarQuery()`: it records the box's text and the header sort, checks a reopened
  state (below) and keeps the sort's tie-break.

### Remembered filters

A signed-in user's pills and search text of a table come back when the table opens again: one WORKING `search_states`
row per user and table (`TableStore`, named `tbl.<table ref>`, found by a token hashing the user id and that name). On
by default (`remember-tables`).

- A "Reset filters" link follows the pills while the table isn't on its defaults: the default rules, no search text.
- A table opts out with `searchbarRememberKey()` returning null. `searchbarRememberContext()` remembers apart within
  a class (SISC's members lists: per list and team). Guests are never remembered; the results page keeps its link.
  A table that doesn't remember keeps its state in the session, under a `ses.…` key (`SessionStore::handles()`),
  whatever `searchbar.store` is: with `DatabaseStore`, a row per display would pile up and push the user's navbar
  rows out of `max-working-states`.
- **Reopening** closes pending pills and open editors, rebuilds the premade rules for the viewer's current team and
  drops filters that no longer fit (see "Stored states"; logged `searchbar.stored_rules_dropped` when read,
  `searchbar.undeclared_filters_dropped` on reopen). A row that no longer
  decodes opens the defaults. The first `searchbarQuery()` runs the query once with LIMIT 0 (1-30 ms on SISC): if it
  fails, the table opens on its defaults, which are stored (logged `searchbar.remembered_filters_reset`); if the
  defaults fail too, the state is kept and the error thrown. The row is marked used (at most one write a day) once that
  check passed.
- **Limits.** A row is created only by the table's own display: a `searchstate/*` request on a `tbl.` key without the
  user's row does nothing. Each user keeps at most `max-remembered-tables` rows (the least recently used go), a state
  over `max-state-kb` isn't written (logged `searchbar.state_too_large`), search text is cut at `max-search-length`.
  `searchbar:prune-links` deletes the rows unused for `table-lifetime-days`.

### Views: favorites and shared views

A "Views" menu next to "Filter" (signed-in users; `searchbarHasViews()` returning false removes it):

- "Save current search to favorites", then the 10 latest own and global favorites of the table's entity (a click
  replaces the table's filters and search text);
- "Share this view...": the page URL with `?searchbar_link={token}`, a LINK snapshot of the filters and search text,
  never changed afterwards (sharing the same view again gives the same token). Anyone signed in who opens it gets a copy
  in place of their own view of that table (premade rules for their team); the parameter then leaves the address bar,
  so a reload keeps their edits. Only a view shared from the same table class and entity opens. Each opening touches
  the link: it is pruned after `link-lifetime-days` unused. `AbstractResultsTable` shares its own link.
- A shared view or favorite that no longer decodes after a deploy is ignored: the viewer keeps their view (logged
  `searchbar.shared_view_undecodable`, `searchbar.favorite_undecodable`; the navbar's favorites too). A results link
  that no longer decodes opens like a pruned one.
- The menu is built when the table is rendered, not on its browses or exports.

### Column headers

`$this->searchbarTh('crm.email', 'email_filter')` in `headers()` adds a funnel to the header: a click (or Enter / Space,
it is focusable) opens the pill editor of the field's last condition, else a new pending pill. It is green while the
field has a condition; the rest of the header keeps Kompo's sort. Only for column filters with a pill editor (other
keys give a plain header). The package's exporter strips it from the headings; another exporter would export its HTML.

Header sorts keep the unique-key tie-break (pages no longer repeat or skip tied rows); a `relation.column` sort is left
to Kompo. SISC: the Person results table (name, email, phone, address, gender, linked to) and the members lists
(gender, brevets).

### Excel export

`getExportableInstance()` returns a `SearchbarTableExport`: the rows the table shows, as strict and in the order of
its header sort (each browse records the sort in the state; a display clears it).

- When the table isn't on its defaults, lines above the headings describe the filters: a title (entity, date and
  time), the search text, each pill and default rule, then a blank row. `searchbarExportDescribesFilters()` returning
  false leaves them out.
- The file is named `{table}-{fields}-{date}`: the table's `$filename` (declare it), then the filtered fields' names (4
  at most) and "search", never their values.
- Text typed in the box within its debounce is exported, described and named.
- The export takes a snapshot of the state (`ArraySearchStore`): a queued export's worker boots the table on it, never
  on the live state.
- A column whose cells are `exclude-export` loses its heading when the first row has one cell per heading, and not
  with `exportChildClass`: otherwise mark its `_Th` `exclude-export` too.
- **A host export with its own query and columns** (SISC's `TeamMembersExport` for the members lists): the Excel
  button posts `$this->searchbarExportParams()`; the export's constructor reads
  `SearchbarExportFilters::fromParams($params, Entity::class)` (null without the keys, for an unknown key or another
  entity's state), `query()` returns `->applyTo($query)` (give the query an ORDER BY: every word is then required) and
  `->lines()` describes the filters.

### Grouped actions

`HasSearchbarGroupedActions` (used by `AbstractResultsTable`) runs actions on a table's checked rows:

- `canRunGroupedAction($action)`: the grouped actions gate (see Security notes), to show or hide the action;
- `searchbarGroupedActionModal($modalClass)`: opens an `AbstractGroupedActionModal` with the props `itemIds`,
  `serviceKey`, `storeKey` and `refresh_id` (the table to refresh);
- `selectedResultIds()`: the checked ids that are among the table's current results, for an action that isn't such a
  modal.

A modal names its action with `protected $groupedAction = 'archive';` (untyped) and acts only on `selectedModels()`.
A table with its own base query overrides `groupedActionsQuery()` in the table and `resultsQuery()` in its modals,
both returning `$this->searchService->getQuery(<that base>)`. A selection over `grouped-actions-max-ids` is refused as
a whole, and so is an "every match" selection: grouped actions run on checked rows only.

## "Open in a table" links

The results page saves its search as a link (`search_states` row of type `LINK`, found by a random token):
`/search-results?link={token}`. Reloading keeps the edits and the link can be shared: its author keeps editing it,
anyone else opening it works on a copy. Links not changed for `searchbar.link-lifetime-days` (10) are deleted by
`php artisan searchbar:prune-links` (scheduled daily; `--days=` overrides the links' lifetime). The same command
deletes the remembered tables unused for `searchbar.table-lifetime-days` (180). It also keeps each user's latest
`recent-searches` recent searches (all of them go when it is 0), deletes the navbar's working states (`DatabaseStore`)
unchanged for `working-lifetime-days` (8), and never touches a `tbl.*` remembered table or a link except by their own
lifetimes.

## Stored states (format v2)

Every stored state is plain data (`StateCodec`): the navbar's session (an array under `searchbarState.{key}`) and the
`search_states` rows as JSON (navbar working states, remembered tables, links and shared views, favorites, recent
searches).

    {"v":2,"entity":"App\\Models\\Crm\\Person","search":"martin","rules":[
      {"id":"l0","t":"premade","key":"active_default"},
      {"id":"r1a2b3c4","t":"filter","key":"email_filter","op":10,"value":"gmail"}]}

- No class name but the entity's, used only when it is a concrete `Searchable` class: any one, not only the navbar's
  registered searchables (a table's own searchable is stored too, e.g. SISC's `TeamMemberSearchable`).
- A rule keeps its id, its filter key and what the user chose (operator, value, columns, a scope's params). Its class
  and SQL column come from the filter when the state is read (`Filterable::ruleFromData()`): a filter whose column
  changed uses the current one. A premade rule is its key, rebuilt for the viewer's current team.
- A rule that no longer fits (a filter or premade key the searchable no longer declares, an operator the field doesn't
  offer, an undeclared scope, a second copy of a premade rule) is dropped and logged (`searchbar.stored_rules_dropped`,
  with its reason); the rest of the state is kept. The navbar session, a working state, a remembered table and a
  results link read by its author are stored once without it. A state whose entity isn't a searchable class, or a
  JSON state over `max-state-kb`, is refused (`searchbar.stored_state_refused`).
- Working states (the navbar's, remembered tables, a results page's link) keep pending pills, open editors, the header
  sort and the panel's open flag. Snapshots (favorites, shared views, recent searches, link copies) don't.
- A host filterable built on `Filterable` gets a default `ruleFromData()` (the stored operator and value through
  `getRuleInstance()`). Override it to check other data, and throw `UnusableRuleData` for data that no longer fits.
- States stored before (v1: serialized rules) are still read everywhere, without instantiating any of their classes
  (`LegacyStateReader`). They are written as v2 the next time they are stored.

`php artisan searchbar:migrate-states` converts the stored rows to v2:
- Run `--dry-run` first. Per type it shows: rows, already v2, v1, to convert, undecodable (left as they are), left v1
  because a rule failed to rebuild (a filterable throwing without a signed-in user: still read), rules kept and
  dropped, and each dropped rule with its row and reason. `--type=` (global, user, link, working, recent) and `--id=`
  narrow it; `--details=` caps the rows listed.
- The real run keeps each converted row's v1 payload in `search_states_v1` and leaves the rows' `updated_at` (their
  prune age) unchanged. A row is written only while it holds exactly what was read (the row locked). A row the
  application writes meanwhile is left alone and reported as "changed meanwhile": run the command again. A second
  run changes nothing.
- Run `--rollback` before downgrading to a release that reads v1 only. Each kept payload goes back while its row still
  holds the v2 the conversion wrote. A row changed since, or written as v2 by the application, is written as v1 from
  its current state (premade rules built without a signed-in user). A kept payload is forgotten only with the write
  that restores its row. Rows whose rules fail to rebuild stay v2 and are reported.
- Every store reads v1 anyway: converting stops the reading of serialized objects and gives the rollback path. The
  app runs without it.

### No lost updates: `mutate()` and the navbar's rows

Every change of a state goes through `SearchStore::mutate($change)`: `$change` gets the latest stored state, read again
under the store's lock, and the state is stored after it (unless it returns `false`; a state it returns replaces the
whole state: a favorite, a recent search, a shared view). The `searchstate/*` actions, the rule forms, the tables'
reopen, reset and views, favorites and recent searches all use it. Changes name their rules by id, so applied to a
newer state they still hit their rule. `$change` may run again: it changes the state only, no other side effect.

- **Database stores** (`DatabaseStore`, `TableStore`, `LinkStore`) read the row `FOR UPDATE` inside a transaction and
  write it by its id: two requests changing one state run one after the other, the second on the first's result (a
  pill applied while an option search runs, two chips clicked fast, two tabs). A first write racing another one fails
  on the unique token and runs again on that row, and so does a deadlock outside a caller's transaction. The row is
  found without a lock, then locked by id: a locking read of a missing token would lock the index gap. When another
  request commits the row in between, MariaDB's snapshot isolation (`innodb_snapshot_isolation`, on by default from
  11.6.2) fails the locking read (1020): the change runs again too. Never inside a transaction opened by the caller
  (its snapshot doesn't see the other request's row). A row lock rather than `Cache::lock()`: it holds across web
  nodes and whatever the cache driver.
- **What a read writes back.** A state read with rules that no longer fit is stored once without them. Not when a rule
  failed to rebuild (`StateCodec::failedDrops()`: a filterable throwing now, no searchable to rebuild with): its stored
  data may be fine, and the rule comes back once it rebuilds.
- **The session can't be locked** (`SessionStore`): Laravel writes the whole session back at the end of every request,
  so a request that overlapped a change (the results' lazy load, an option search, another tab) can put back the state
  it read.
- **`store => DatabaseStore`** keeps the navbar's state of a signed-in user in a WORKING row per user and store key (a
  token hashing both; guests keep the session). A page display draws its store key and writes nothing: a row is
  written when that display changes its search. A page displayed before the switch keeps its pills: the first write
  moves the session's state into the row. Rows unchanged for `working-lifetime-days` (8) are pruned, and a user keeps
  at most `max-working-states` (100; the least recently changed go). Remembered tables (`tbl.*`) are never pruned by
  these; a key that only looks like a table's (`TBL.x`, `tbl.a b`: refused by `TableStore::handles()`) is named
  apart (`~tbl…`), so the navbar's prune and cap apply to it.
- Tests: `SEARCHBAR_TEST_STORE=database` runs the host suite with the navbar's states in rows
  (`tests/sisc/README.md`); `StoreConcurrencyTest` interleaves requests, two real PHP processes included.

## Configuration (`config/searchbar.php`)

| Key | Default | |
|---|---|---|
| `store` | `SessionStore` | Where the navbar search state lives: `SessionStore`, or `DatabaseStore` (rows, changes under a row lock; guests keep the session). |
| `working-lifetime-days` | `8` | Age after which the navbar's unchanged working states (`DatabaseStore`) are pruned. |
| `max-working-states` | `100` | Navbar working states per user (one per page display that changed its search); one more deletes the least recently changed. |
| `service-stores` | `['searchTable' => LinkStore]` | Store of specific search services. |
| `link-lifetime-days` | `10` | Age after which unused links (results pages, shared views) are pruned. |
| `remember-tables` | `true` | Tables remember each signed-in user's filters and search text; `false`: back to the defaults on reload. |
| `table-lifetime-days` | `180` | Age after which unused remembered tables are pruned. |
| `max-remembered-tables` | `200` | Remembered tables per user; one more deletes the least recently used. |
| `max-state-kb` | `256` | Largest stored state (v2 JSON), written or read: working state, remembered table, link, shared view, favorite, recent search. A larger one isn't written (logged), and a larger stored one is refused. |
| `max-search-length` | `255` | Characters of search text kept (navbar and tables). |
| `recent-searches` | `8` | Recent navbar searches kept per user (the oldest go); `0` turns them off. |
| `table-menu-section-max-options` | `20` | A table's "Filter" menu shows a section's option chips up to this many options; `0`: none. |
| `default-results-entity` | — | Searchable shown in the panel before an entity is picked. |
| `max-count-searchable` | `100` | Entity counts in the panel stop there (`100+`). The "Open in a table" number is the full count (the results page total), counted once. |
| `search-min-chars` | `3` | Letters or digits of at least one typed word before the navbar panel counts and lists results ("a b", "j-p" are too short): below it the panel says how much to type and every entity count is "?". `0` turns it off; ngram / CJK hosts: set 2 or lower. Tables and "Search by" chips aren't gated. |
| `max-relation-options` | `2000` | Options a relation select loads when it doesn't search on the server (plus the selected ones). |
| `relation-search-threshold` | `500` | Above this many records, a searchable relation select searches on the server. |
| `relation-search-min-chars` | `3` | Characters of at least one typed word before such a select searches. |
| `relation-search-limit` | `50` | Options one server search returns. |
| `fulltext.min-token` | `3` | Shorter words are LIKE word starts, not MATCH (at least `innodb_ft_min_token_size`). |
| `fulltext.max-token` | `null` | Longer words are LIKE word starts; `null` reads the server's `innodb_ft_max_token_size`. |
| `scope-macros` | `withTrashed`, `onlyTrashed`, `withoutTrashed` | Builder macros a scope rule may call besides local scopes. |
| `grouped-actions-gate` | `null` | Invokable class, `__invoke($searchable, string $action): bool`, replacing the grouped actions' permission check. |
| `grouped-actions-max-ids` | `500` | Most checked rows one grouped action takes. |
| `route-middleware` | `['web', 'auth']` | Middleware of the `searchstate/*` routes. |

## Security notes

- Rules sent to the browser (option chips) are HMAC-signed and verified before being unserialized.
- Stored states are never unserialized as objects. v2 is JSON, and its entity class is checked before use. v1 is read
  with `allowed_classes => false`. Stored data never picks a SQL column, a rule class or a scope: those come from the
  filter declared now.
- Scope rules only call the model's local scopes; select-scope filters only accept their declared scopes.
- Pill values are escaped (Kompo renders labels as HTML), and so are the option labels of relation and select filters
  and favorite names in a table's Views menu.
- The state keys a table posts (`searchstate/*`, `searchbarExportParams()`) only reach the user's own states: the
  session, or a row whose token hashes the user id and the key. A request never creates a remembered table's row.
- A link is only ever a token of its own format (`LinkStore::isToken()`: 40 letters or digits, as `newToken()` draws
  it). Another user's WORKING or RECENT token (64 hex, predictable from their id and a key) posted as a results page's
  store key creates nothing: it made that user's table or recent search fail to be written (the unique token taken).
- A shared view carries filters, not rows: the viewer's table runs its own base query and model security on them.
- **Grouped actions** (bulk delete, host actions) pass `GroupedActionGate` on every submit: kompo-auth WRITE on the
  model's permission key in any of the user's teams, fail-closed on an unseeded key (super admins and the global
  bypass pass). Bulk delete then lists the rows whose `deletable()` refuses and leaves them alone; model security
  checks each remaining row, all or nothing: one refused row gives a 403 and nothing is deleted. A searchable's `groupedActionPermission(string $action)` returns the key to check, `null`
  (no explicit check) or `false` (never); `grouped-actions-gate` replaces the whole check. A host subclass of
  `AbstractGroupedActionModal` defining `authorize()` must call `parent::authorize()`.
- A searchable whose kompo-auth permission key is unseeded gets no read scope from model security, so its
  `baseSearchQuery()` must restrict itself. SISC's `Note`: its visibility restrictions, plus notes of teams where the
  user has Person READ, their own notes and notes about them; skipped under `globalSecurityBypass()`. A premade rule
  ("only in this team") is not a security control: users can turn it off.
- A searchable subclassing another secured model implements `HasPermissionKey` to share its key (SISC `Committee` →
  `Team`): model security and the grouped actions gate then check Team WRITE.
- SISC's invoice results offer Approve and Void through the finance `InvoiceService`, never a status write and never
  bulk delete (`Invoice::groupedActionPermission()`: `false` for `delete`, else Invoice WRITE). Its modals refuse a
  link switched to another entity, and the service re-checks each invoice's team in one transaction.
