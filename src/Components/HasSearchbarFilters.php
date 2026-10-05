<?php

namespace Kompo\Searchbar\Components;

use Illuminate\Support\Str;
use Kompo\Core\KompoAction;
use Kompo\Core\RequestData;
use Kompo\Komponents\KomponentManager;
use Kompo\Searchbar\Components\Concerns\ExportsSearchbarFilters;
use Kompo\Searchbar\Components\Concerns\HasSearchbarColumnHeaders;
use Kompo\Searchbar\Components\Concerns\HasSearchbarViews;
use Kompo\Searchbar\Components\Concerns\RemembersSearchbarState;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumn;
use Kompo\Searchbar\SearchItems\Sections\SearchColumnSection;
use Kompo\Searchbar\SearchItems\Stores\SessionStore;
use Kompo\Searchbar\SearchService;

/**
 * The searchbar engine on any kompo Table / Query: a search box, "+ Filter" (the searchable's column filters and
 * default rules) and the editable filter pills of the navbar (FilterableRule::render), on the table's own state.
 *
 * In a host table:
 *
 *     use HasSearchbarFilters;
 *     protected $searchableEntity = Person::class;         // a Searchable model
 *
 *     public function query() { return $this->searchbarQuery(Person::where(...)); }   // or searchbarQuery()
 *     public function top()   { return _Rows($this->searchbarFilters(), ...); }
 *
 * A table defining created() calls $this->bootSearchbar() in it. Filter and pill actions refresh the table (its menu
 * from inside it; pills, the custom filters modal and its rule forms by the table's id: SearchService::
 * refreshTargets()); the search box only reloads the rows (a Query filter: it keeps the focus).
 * Each table gets its own search service ('table-' + class name) unless it sets $serviceKey.
 * Each user's filters and search text of a table are remembered (RemembersSearchbarState): opt out per table with
 * searchbarRememberKey() returning null, or for all tables with searchbar.remember-tables. The first searchbarQuery()
 * of a reopened table checks the remembered filters still run (else the table opens on its defaults).
 * Its Excel export (ExportsSearchbarFilters) exports the rows it shows, its filters described above them.
 * Its "Views" menu (HasSearchbarViews) saves and loads favorites and shares the view (?searchbar_link=). Its headers
 * may open pills (HasSearchbarColumnHeaders::searchbarTh()); a header sort keeps the unique-key tie-break.
 *
 * A table shown on several pages may take the searchbar on some only: its created() boots it there, and elsewhere
 * searchbarQuery() hands its base query back, searchbarFilters() / searchbarAdvancedFilters() draw nothing and
 * searchbarTh() is a plain header. searchbarAdvancedFilters() folds the filters under "Advanced filters", below the
 * table's own controls.
 */
trait HasSearchbarFilters
{
    use SearchKomponentUtils;
    use RemembersSearchbarState;
    use ExportsSearchbarFilters;
    use HasSearchbarViews;
    use HasSearchbarColumnHeaders;

    // Being opened in this request (the page display, or rebuilt by its parent): see searchbarShownSort().
    protected bool $searchbarOpening = false;

    // bootSearchbar() ran: the table takes the searchbar (see the class docblock).
    protected bool $searchbarBooted = false;

    public function created()
    {
        $this->bootSearchbar();
    }

    protected function bootSearchbar()
    {
        $this->searchbarBooted = true;

        // A stable id: the custom filters modal refreshes the table by it.
        if (!$this->id) {
            $this->id = 'searchbar-table-' . Str::kebab(class_basename(static::class));
        }

        // Every change of the table's state (its pills and their editors, toggles, the custom filters modal's rows and
        // forms) refreshes the whole table (SearchService::refreshTargets()), named: not the navbar's parts.
        searchService($this->getServiceKey())->setRefreshTarget($this->id);

        // A queued export's worker re-boots the table (no session, maybe after the user changed the filters): the
        // filters the export was asked with, never the live state (ExportsSearchbarFilters).
        if ($this->searchbarExportSnapshot !== null) {
            $this->storeKey = $this->prop('storeKey');
            $this->searchbarBootFromSnapshot();

            return;
        }

        // Opening (page display, or rebuilt by its parent): the user's remembered state of this table, if it remembers.
        $opening = $this->searchbarOpening = $this->searchbarPickStoreKey();

        // A table that doesn't remember keeps its state in the session (a ses.… key), whatever the navbar's store: in
        // rows (DatabaseStore), each display would write one and push the user's navbar rows (other tabs) out of their cap.
        if (!$this->prop('storeKey') && !isset(config('searchbar.service-stores', [])[$this->getServiceKey()])) {
            $this->store(['storeKey' => SessionStore::newKey($this->getServiceKey())]);
        }

        $this->setSearchProps();

        $entity = property_exists($this, 'searchableEntity') ? $this->searchableEntity : null;

        // A shared view in the page URL replaces the user's view of this table (HasSearchbarViews), then is reopened.
        if ($opening) {
            $this->searchbarOpenSharedLink($entity);
        }

        if ($entity && $this->state->getSearchableEntity() !== $entity) {
            // A new state, or a remembered one of another entity: the table's entity with its default rules (unless
            // another request, a second tab opening the table, just did it).
            $this->state = $this->searchService->getStore()->mutate(function ($state) use ($entity) {
                if ($state->getSearchableEntity() === $entity) {
                    return false;
                }

                $state->setSearchableEntity($entity)->setSearch(null);
                $state->setRules($state->getSearchableInstance()->getDefaultRulesApplied()->values());
            });
            $this->searchableInstance = $this->state->getSearchableInstanceForResultsPanel();
        } elseif ($opening) {
            $this->searchbarReopen();
        }
    }

    // Not the navbar's service: sharing its singleton, a table's store key would replace the navbar's mid-request.
    protected function getServiceKey()
    {
        return property_exists($this, 'serviceKey') && $this->serviceKey
            ? $this->serviceKey
            : 'table-' . Str::kebab(class_basename(static::class));
    }

    /**
     * The table's rows: $baseQuery (else the searchable's baseSearchQuery()) filtered by the table's search state. A
     * browse sorted by a header and the export are in the order of that sort, the unique key last
     * (HasSearchbarColumnHeaders::searchbarHeaderSorted(), ExportsSearchbarFilters::searchbarExportOrder()).
     */
    public function searchbarQuery($baseQuery = null)
    {
        // Not booted: the page shows the table without the searchbar.
        if (!$this->searchbarBooted) {
            return $baseQuery;
        }

        $this->searchbarRecordRequest();

        // A reopened remembered state: checked once, before the table uses it (RemembersSearchbarState).
        $query = $this->searchbarCheckReopenedQuery
            ? $this->searchbarCheckedQuery($baseQuery)
            : $this->searchService->getQuery($baseQuery);

        return $this->searchbarExporting() ? $this->searchbarExportOrder($query) : $this->searchbarHeaderSorted($query);
    }

    /**
     * What the request says about the rows shown, stored with the state when it changed (one write):
     * - the search box text. The box is a Query filter: its text comes with each browse of the rows, and with the
     *   export (typed within the box's debounce). Only then: a refresh after a filter action carries the box's old
     *   text, which that action may just have turned into a filter;
     * - the header sort of the rows (searchbarShownSort()): the table's export and grouped actions don't carry it,
     *   and the full-text search is strict under a header sort (SearchService::sortedByHeader()).
     */
    protected function searchbarRecordRequest(): void
    {
        $shown = [];

        if ((KompoAction::is('browse-items') || $this->searchbarExporting()) && request()->has('searchbar_search')) {
            $shown['search'] = SearchService::capSearchText(request('searchbar_search'));
        }

        if (($sort = $this->searchbarShownSort()) !== null) {
            $shown['sort'] = $sort;
        }

        // Written only when it changes the state: a browse doesn't lock and rewrite a remembered row each time. Put in the
        // state read, then in the stored one (the same object while unchanged since: already in it).
        if ($shown && $this->searchbarRecordShown($this->state, $shown)) {
            $this->state = $this->searchService->getStore()->mutate(fn($state) => $state === $this->state
                || $this->searchbarRecordShown($state, $shown));
        }
    }

    /** Puts the search text and sort of the rows shown ($shown) in $state: whether that changed it. */
    protected function searchbarRecordShown($state, array $shown): bool
    {
        $changed = false;

        // No text is null or '' (a stored state reads back ''): an emptied box doesn't rewrite a remembered row on
        // every browse.
        if (array_key_exists('search', $shown) && ($shown['search'] ?? '') !== ($state->getSearch() ?? '')) {
            $state->setSearch($shown['search']);
            $changed = true;
        }

        if (array_key_exists('sort', $shown)) {
            $before = $state->getSort();
            $changed = $before !== $state->setSort($shown['sort'])->getSort() || $changed;
        }

        return $changed;
    }

    /**
     * The header sort of the rows this request shows ('' for none), or null when it shows none (its export, a grouped
     * action, an option search: the recorded sort stays). A browse carries the table's current sort (X-Kompo-Sort,
     * sent empty when unsorted); a display (the page, a refresh, a parent rebuilding the table) shows the first page
     * in the query's own order: Kompo sorts browses only.
     */
    protected function searchbarShownSort(): ?string
    {
        if (KompoAction::is('browse-items')) {
            // Also once this table took the header over (searchbarHeaderSorted()). A table built while another
            // komponent's browse renders its rows is displayed, unsorted: that header isn't its sort.
            return $this->searchbarIsRequestTarget() ? SearchService::headerSort() : '';
        }

        if ($this->searchbarExporting()) {
            return null;
        }

        return !KompoAction::header() || KompoAction::is('refresh-self') || $this->searchbarOpening ? '' : null;
    }

    /**
     * Search box + "+ Filter" + "Views" + the editable pills, and the hidden links of the searchbarTh() headers. Every
     * field ignores the model: Queries filter by named fields.
     */
    public function searchbarFilters()
    {
        // A relation select of a pill editor searching its options: Kompo runs the Query's top() just to call
        // searchbarRelationOptions(); the pills (label queries, the editor's size check) ran on every keystroke.
        if (!$this->searchbarBooted || KompoAction::is('search-options')) {
            return null;
        }

        $searchable = $this->state->getSearchableInstanceForResultsPanel();

        if (!$searchable) {
            return null;
        }

        $rules = $this->state->getRules();
        $reset = $this->searchbarResetLink();

        // .searchbar-scope: what searchbarBusy() locks for this table's actions; the table's refresh reloads this
        // field, which unlocks.
        return _Rows(
            _Hidden()->name('searchbar_client_js', false)
                ->onLoad(fn($e) => $e->run('() => { (' . searchbarClientJs() . ')(); window.searchbarUnlock && searchbarUnlock();'
                    . ' window.searchbarSyncFilterMenus && searchbarSyncFilterMenus();'
                    . ' window.searchbarKeepTableFilters && searchbarKeepTableFilters(' . json_encode($this->searchbarScopeClass()) . ');'
                    . ' window.searchbarFocusTableEditor && searchbarFocusTableEditor(' . json_encode($this->searchbarScopeClass()) . '); }')),
            $this->searchbarForgetLinkInUrl(),
            $this->searchbarThLinks(),
            _Flex(
                _Input()->name('searchbar_search', false)->default($this->state->getSearch())
                    ->placeholder('filter.search-placeholder')
                    ->icon(_Sax('search-normal-1', 18))
                    ->noAutocomplete()->dontSubmitOnEnter()
                    // Each browse stores the text: explicit, not left to Kompo's Input default (500 today).
                    ->filter()->debounce(500)
                    ->class('mb-0 flex-1 min-w-0 searchbar-search-box'),
                $this->searchbarAddFilter($searchable),
                $this->searchbarViewsMenu(),
            )->class('gap-3 items-center'),
            $rules->isEmpty() && !$reset ? null : _Flex(
                // Addressed by their ids (FilterableRule::render()), not their positions. One collection: Kompo only
                // flattens a single array argument.
                $rules->map(fn($rule) => $rule->render())->push($reset)->filter()->values(),
            )->class('search-rule-pills flex-wrap items-center gap-2 mt-3'),
        )->class('searchbar-scope ' . $this->searchbarScopeClass());
    }

    /**
     * A refresh of the table (every searchbar action refreshes it) draws the table's own filter fields (its bar above
     * the searchbar) with the values the refresh posted: Kompo draws a Query's filters with their defaults, which
     * emptied that bar. The page posts the current values (searchbarKeepTableFilters() in searchbarClientJs()). The
     * searchbar's own fields (its box, the pill editors) are drawn from the state.
     */
    public function prepareOwnElementsForDisplay($renderedElements)
    {
        $elements = parent::prepareOwnElementsForDisplay($renderedElements);

        if ($this->searchbarBooted && KompoAction::is('refresh-self')) {
            KomponentManager::collectFields($this)->each(function ($field) {
                $name = $field->name ?? null;

                if (is_string($name) && $name !== '' && !preg_match('/^(searchbar_|inline_)/', $name)
                    && (request()->has($name) || request()->has(str_replace('.', '_', $name)))) {
                    $field->value(RequestData::get($name));
                }
            });
        }

        return $elements;
    }

    /**
     * The searchbar's filters (searchbarFilters()) folded under "Advanced filters", for a table keeping its own controls
     * (its search input, toggles) above them. Open while the state is off its defaults: its pills and search text apply
     * to the rows, never hidden (the box is rendered folded too: each browse carries its text). The count: pills besides
     * the premade rules, and the search text. Null when the table didn't boot the searchbar.
     */
    public function searchbarAdvancedFilters()
    {
        if (!$filters = $this->searchbarFilters()) {
            return null;
        }

        $count = $this->state->countOffDefaults();

        return _Collapsible($filters->class('pt-2'))
            ->titleLabel(_Flex(
                _Sax('filter', 18),
                _Html(__('filter.advanced-filters') . ($count ? ' (' . $count . ')' : '')),
            )->class('gap-2 items-center text-sm font-semibold text-level1 hover:text-greenmain'))
            ->expandedByDefault(!$this->searchbarIsDefaultState())
            ->class('searchbar-advanced-filters gap-0');
    }

    /**
     * Names this table's bar for its "Filter" menu links: the menu is teleported to <body> (condoedge Dropdown
     * override), so its links find the bar (search box, lock) by this class rather than by DOM ancestry.
     */
    protected function searchbarScopeClass(): string
    {
        return 'searchbar-scope-' . substr(md5($this->getServiceKey() . '|' . $this->storeKey), 0, 12);
    }

    /**
     * The column filters and the default rules. A column filter turns the text typed in the search box into one more
     * condition on that field (or a pending pill when the text can't be a value of the field): it needs text, so the
     * fields are disabled while the box is empty (.searchbar-needs-search, see searchbarClientJs()).
     */
    protected function searchbarAddFilter($searchable)
    {
        // Each pick adds one more condition on the field (SearchStateController::addColumnCondition()): the label counts
        // the field's conditions ("Email (2)").
        $conditions = $this->state->getFilterableRules()->reject->isPendingValue()->countBy(fn($rule) => (string) $rule->getKeyReference());

        $columns = collect($searchable->decoratedFilterables())
            ->filter(fn($filterable) => $filterable instanceof FilterableColumn)
            ->map(fn($filterable, $key) => $this->searchbarAction(
                _DropdownLink(__($filterable->getName()) . (($count = $conditions[$key] ?? 0) ? " ({$count})" : ''))
                    ->class('py-2 px-3 searchbar-needs-search'),
                'searchstate.column-chip', ['key' => $key, 'add' => 1],
            ))->values()
            ->prepend(_Html('filter.type-then-pick')->class('px-3 py-2 text-xs text-gray-500 max-w-[16rem]'));

        $defaults = $searchable->getPremadeRules()->map(function ($rule) {
            $isActive = $rule->isActive();
            $isChecked = $rule->isInverse() ? !$isActive : $isActive;
            $toggleField = 'toggle' . str_replace('.', '_', $rule->getKey());

            return $this->searchbarAction(
                _DropdownLink($rule->getDescription() ?: $rule->getName())->icon(_Sax($isChecked ? 'tick-square' : 'stop', 16))->class('py-2 px-3'),
                'searchstate.toggle-default', ['key' => $rule->getKey(), $toggleField => $isChecked ? 0 : 1],
            );
        })->values();

        // First item: any field with its operator and a value of its type (dates, numbers, options, scopes...), in
        // the navbar's modal. Needs no search text.
        $filtersCount = $this->state->getFilterableRules()->count();
        $custom = _DropdownLink(__('filter.custom-filters') . ($filtersCount ? " ({$filtersCount})" : '') . '…')
            ->icon(_Sax('setting-4', 16))
            ->class('py-2 px-3 border-b border-gray-200 font-medium searchbar-for-' . $this->searchbarScopeClass())
            ->selfGet('getSearchbarCustomFiltersModal')->inModal();

        return _Dropdown('filter.add-filter')->icon(_Sax('filter-add', 16))->button()->alignRight()
            ->submenu(...collect([$custom])->concat($this->searchbarMenuSections($searchable))->concat($columns)->concat($defaults)->all());
    }

    /**
     * The searchable's option chips (the navbar panel's sections: entity options, select scopes), one click away in the
     * "Filter" menu. Not the "Search by" section: its fields are the menu's list. Only the short ones (at most
     * searchbar.table-menu-section-max-options options each): a long list belongs in the custom filters modal. A chip
     * toggles its option in the table's state (SearchStateController::toggleSectionRule()) and refreshes the table.
     */
    protected function searchbarMenuSections($searchable)
    {
        $max = max(0, (int) config('searchbar.table-menu-section-max-options', 20));

        // A misdeclared section (its created() throws) or failing options lose the chips, not the table.
        $sections = $max > 0 ? rescue(fn() => collect($searchable->decoratedSections()), collect(), true) : collect();

        return $sections
            ->reject(fn($section) => $section instanceof SearchColumnSection)
            ->filter(fn($section) => rescue(fn() => !$section->hasMoreOptionsThan($max) && $section->optionsOnce()->isNotEmpty(), false, true))
            ->map(fn($section) => $section
                ->inTable(fn($el, string $route, array $params) => $this->searchbarAction($el, $route, $params))
                ->showOptions()->class('px-3 py-2 border-b border-gray-200 max-w-xs searchbar-menu-section'))
            ->values();
    }

    /** The custom filters modal on this table's state: it refreshes this table, and keeps its entity. */
    public function getSearchbarCustomFiltersModal()
    {
        return $this->instanciateSearchKomponent(CustomFiltersModal::class, [
            'serviceKey' => $this->getServiceKey(),
            'refresh_id' => $this->id,
        ]);
    }

    /** Posts a state change for this table's state, then refreshes the table (see searchbarBusy()). */
    protected function searchbarAction($el, string $route, array $params = [])
    {
        return $el->class('searchbar-for-' . $this->searchbarScopeClass())
            ->onClick(fn($e) => $e->run('() => { window.searchbarBusy && searchbarBusy(); }')
            && $e->post($route, $this->searchService->stateParams($params))->withAllFormValues()->refresh());
    }
}
