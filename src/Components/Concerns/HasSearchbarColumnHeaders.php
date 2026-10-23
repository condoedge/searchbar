<?php

namespace Kompo\Searchbar\Components\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Kompo\Core\KompoAction;
use Kompo\Core\KompoInfo;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumn;
use Kompo\Searchbar\SearchService;

/**
 * Column headers of a table (part of HasSearchbarFilters), opt-in:
 *
 *     public function headers()
 *     {
 *         return [_Th()->class('w-8'), $this->searchbarTh('crm.email', 'email_filter'), $this->searchbarTh('crm.name', 'name_filter')->sort('last_name')];
 *     }
 *
 * searchbarTh() adds a funnel to the header: a click on it (or Enter / Space, it is focusable) opens the pill editor of
 * the field's last condition, else a new pending pill (more conditions come from the "Filter" menu or the custom filters
 * modal). Green while the field has a condition. The rest of the header keeps Kompo's behavior (sort). A header label is HTML (Th.vue v-html), so the
 * funnel clicks a hidden Kompo link of the table's bar (searchbarClientJs()), which posts searchstate/column-filter.
 *
 * Header sorts keep the unique-key tie-break (searchbarHeaderSorted()). Defines no created().
 */
trait HasSearchbarColumnHeaders
{
    // Filter keys of the headers built by searchbarTh() in this request: searchbarFilters() renders a hidden link for
    // each. Kompo builds the headers before the filters on every display (QueryDisplayer::prepareConfigurations()).
    protected array $searchbarThKeys = [];

    /**
     * A column header whose funnel opens the pill editor of $filterKey: a column filter of the table's entity with a
     * pill editor (FilterableColumn), else a plain header.
     */
    protected function searchbarTh(string $label, string $filterKey)
    {
        $text = e(__($label));

        if (!$this->searchbarThFilterable($filterKey)) {
            return _Th($text);
        }

        $this->searchbarThKeys[$filterKey] = true;

        $active = $this->state->getFilterableRules()
            ->contains(fn($rule) => $rule->getKeyReference() === $filterKey && !$rule->isPendingValue());

        // Focusable, Enter / Space open it too (searchbarClientJs()): a keyboard user reaches the pill from the header.
        $title = e(__('filter.filter-column'));

        return _Th($text . '<span class="searchbar-th-filter inline-flex ml-1 cursor-pointer ' . ($active ? 'text-greenmain' : 'text-gray-400') . '"'
            . ' data-searchbar-th="' . e($filterKey) . '" data-searchbar-scope="' . e($this->searchbarScopeClass()) . '"'
            . ' title="' . $title . '" aria-label="' . $title . '" role="button" tabindex="0">' . static::searchbarFunnelSvg() . '</span>');
    }

    /** The column filter of $filterKey a header can open, or null (unknown key, a scope, no pill editor). */
    protected function searchbarThFilterable(string $filterKey): ?FilterableColumn
    {
        $filterable = $this->searchableInstance ? rescue(fn() => $this->searchableInstance->filterable($filterKey), null, false) : null;

        return $filterable instanceof FilterableColumn && $filterable->supportsInlineEdit() ? $filterable : null;
    }

    protected static function searchbarFunnelSvg(): string
    {
        static $svg = null;

        return $svg ??= _SaxSvg('filter', 14);
    }

    /** The hidden links the header funnels click (searchbarClientJs()), one per filter key: they post for this table. */
    protected function searchbarThLinks()
    {
        if (!$this->searchbarThKeys) {
            return null;
        }

        // The key in a class too (when it is class-safe): classes are always rendered, whatever the element's attributes.
        return _Rows(
            collect(array_keys($this->searchbarThKeys))->map(fn($key) => $this->searchbarAction(
                _Link()->class('searchbar-th-link' . (preg_match('/^[\w-]+$/', (string) $key) ? ' searchbar-th-key-' . $key : ''))
                    ->attr(['data-searchbar-key' => (string) $key]),
                'searchstate.column-filter', ['key' => $key],
            ))->values()->all(),
        )->class('hidden');
    }

    /**
     * A browse sorted by a column header, sorted here with the unique key last (the order of the table's export, see
     * ExportsSearchbarFilters::searchbarExportOrder()). Kompo sorts such a browse after query() (DatabaseQuery::
     * handleSort()) and clears every ORDER BY on the way, the tie-break SearchService::getQuery() adds last included:
     * tied rows repeated or vanished across pages. Kompo then doesn't sort it again (SearchService::takeOverHeaderSort()).
     * A relation.column sort is left to Kompo (it joins the relation): no tie-break there, as before.
     */
    protected function searchbarHeaderSorted($query)
    {
        // The header's sort as recorded by searchbarRecordRequest(): a sort failing its format stays Kompo's, as before.
        $sort = KompoAction::is('browse-items') && SearchService::headerSort() !== '' && $this->searchbarIsRequestTarget()
            ? $this->state->getSort()
            : null;
        $sorted = $sort ? $this->searchbarSortedBy($query, $sort) : null;

        if (!$sorted) {
            return $query;
        }

        SearchService::takeOverHeaderSort();

        return $sorted;
    }

    /**
     * The request is for this table: its header sort is this table's. Kompo sorts only the komponent a request is for
     * (QueryFilters::filterAndSort()); a table built while another komponent's browse renders its rows is displayed.
     * By class, from the request's boot info (its komponent id may not be set yet while query() runs). A request without
     * boot info (not from Kompo's front end) is taken as this table's.
     */
    protected function searchbarIsRequestTarget(): bool
    {
        if (!request()->hasHeader(KompoInfo::$key)) {
            return true;
        }

        return rescue(fn() => (KompoInfo::getKompo()['kompoClass'] ?? null) === static::class, false, false);
    }

    /**
     * $query in the order of the Kompo sort $sort ("column:DIR|other:DIR", as Kompo applies it: every other order
     * replaced) then the unique key, or null when Kompo must apply it (a relation.column sort, not an Eloquent builder).
     */
    protected function searchbarSortedBy($query, string $sort)
    {
        if (!$query instanceof Builder) {
            return null;
        }

        $model = $query->getModel();
        $orders = collect(explode('|', $sort))->map(function ($columnDirection) {
            [$column, $direction] = explode(':', $columnDirection) + [1 => 'ASC'];

            return [$column, strtoupper($direction) === 'DESC' ? 'desc' : 'asc'];
        });

        if ($orders->contains(fn($order) => str_contains($order[0], '.') && $model->isRelation(Str::before($order[0], '.')))) {
            return null;
        }

        $query->reorder();
        $orders->each(fn($order) => $query->orderBy($order[0], $order[1]));

        return $query->orderBy($model->getQualifiedKeyName());
    }
}
