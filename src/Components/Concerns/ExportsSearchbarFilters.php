<?php

namespace Kompo\Searchbar\Components\Concerns;

use Illuminate\Support\Str;
use Kompo\Searchbar\Exports\SearchbarExportFilters;
use Kompo\Searchbar\Exports\SearchbarTableExport;

/**
 * The Excel export of a table (part of HasSearchbarFilters), through condoedge utils' ExportPlugin
 * (_ExcelExportButton()): getExportableInstance() gives a SearchbarTableExport of the rows the table shows, filtered,
 * as strict and in the order of its header sort, with its filters described above the column headings when the table
 * isn't on its defaults. The file is named after the table and the filtered fields (never their values).
 *
 * The export takes a snapshot of the state: the table reads it from memory from then on, and a queued export's worker
 * (SendExportViaEmail re-boots the table, maybe after the user changed the filters, without session) re-boots it on
 * that snapshot, never on the live state.
 *
 * A table exporting another komponent (an export variant with more columns) returns
 * $this->searchbarTableExport(new static([... 'storeKey' => $this->prop('storeKey')])). A host export with its own
 * query and columns (a Maatwebsite export) follows the filters with SearchbarExportFilters: the table posts
 * searchbarExportParams() with it. Defines no created().
 */
trait ExportsSearchbarFilters
{
    // The state the export was asked with (SearchState::toSnapshotArray()): see searchbarUseSnapshot().
    protected ?array $searchbarExportSnapshot = null;

    // The table's own $filename, before the export named the file after its filters.
    protected ?string $searchbarExportBase = null;

    // ExportPlugin's methods (posted as ?method=, kept in a queued export's request).
    protected static array $searchbarExportMethods = ['exportToExcel', 'directExportToExcel', 'exportToExcelViaEmail', 'exportToExcelRaw'];

    // A table that didn't boot the searchbar (a page showing it without) exports itself, as without this trait.
    public function getExportableInstance()
    {
        return $this->searchbarBooted ? $this->searchbarTableExport() : $this;
    }

    /**
     * The export of $component's rows (default: this table) with this table's filters. A komponent built by the host
     * is booted here, as ExportPlugin boots the komponent it gets; it must use HasSearchbarFilters on this table's
     * state (its storeKey).
     */
    protected function searchbarTableExport($component = null): SearchbarTableExport
    {
        // The search box text comes with the export (typed within the box's debounce, or not stored yet): the rows
        // search it (searchbarQuery()), so the snapshot, the filter lines and the file name take it first. Stored
        // with the live state, as the pending browse would.
        if ($this->searchbarExporting()) {
            $this->searchbarRecordRequest();
        }

        $snapshot = $this->state->toSnapshotArray();
        $lines = $this->searchbarExportDescribesFilters() && !$this->searchbarIsDefaultState() ? $this->searchbarExportLines() : [];

        $this->searchbarNameExportFile();

        if ($component && $component !== $this) {
            $component->bootForAction();
        }

        $component = $component ?: $this;
        $component->searchbarUseSnapshot($snapshot);

        return new SearchbarTableExport($component, $lines);
    }

    /**
     * From now on (and when a worker re-boots this table: bootSearchbar()), the table's state is $snapshot, in memory:
     * its own search service with an ArraySearchStore. The live state (session, remembered row, link) is never read
     * or written by the export.
     */
    public function searchbarUseSnapshot(array $snapshot): void
    {
        $this->searchbarExportSnapshot = $snapshot;
        $this->searchbarBootFromSnapshot();
    }

    protected function searchbarBootFromSnapshot(): void
    {
        // Not the searchService() singleton: the rest of the request (a sync queue runs the job in it) keeps the live one.
        $this->searchService = SearchbarExportFilters::serviceFor($this->getServiceKey(), $this->storeKey ?: $this->prop('storeKey'), $this->searchbarExportSnapshot);
        $this->state = $this->searchService->getStore()->getState();
        $this->searchableInstance = $this->state->getSearchableInstanceForResultsPanel();
    }

    /**
     * For a host export with its own query and columns, built in a later request (a modal asking the export options):
     * the params naming this table's state, to post with the export along with the table's form values (the search
     * box text). The export reads the filters with SearchbarExportFilters::fromParams().
     */
    public function searchbarExportParams(): array
    {
        return ['searchbar_service' => $this->getServiceKey(), 'searchbar_store' => $this->storeKey ?: $this->prop('storeKey')];
    }

    /** False: the file has only the column headings and rows, even when filtered. */
    protected function searchbarExportDescribesFilters(): bool
    {
        return true;
    }

    /** The request exports the table (ExportPlugin's methods, also in a queued export's worker). */
    protected function searchbarExporting(): bool
    {
        return in_array(request('method'), static::$searchbarExportMethods, true);
    }

    /**
     * ExportPlugin names the file after the table's $filename: set to searchbarExportFilename() when the table declares
     * one (a property it doesn't declare would be a dynamic one; the file is then "exported-file").
     */
    protected function searchbarNameExportFile(): void
    {
        if (!property_exists(static::class, 'filename')) {
            return;
        }

        $this->searchbarExportBase ??= is_string($this->filename) ? $this->filename : '';
        $this->filename = $this->searchbarExportFilename();
    }

    /** The table's name in its file name: its $filename (a translation key), else its entity's name. */
    protected function searchbarExportBaseName(): string
    {
        $base = $this->searchbarExportBase ?? (property_exists(static::class, 'filename') && is_string($this->filename) ? $this->filename : '');

        return $base !== '' ? (string) __($base) : (string) $this->state->getSearchableInstance()?->searchableName();
    }

    /**
     * "{table}-{field}-{field}-{date}", slugged: the names of the filtered fields (4 at most) and "search" for search
     * text, never their values: a name or an email typed in a filter would end up in file names, mail attachments and
     * download logs.
     */
    protected function searchbarExportFilename(): string
    {
        $fields = $this->state->getFilterableRules()->reject->isPendingValue()
            ->map(fn($rule) => rescue(fn() => (string) $rule->getFilterable()?->getFilterName(), '', false))
            ->filter()->map(fn($name) => (string) __($name))->unique()->take(4)->values();

        if (trim((string) $this->state->getSearch()) !== '') {
            $fields->prepend((string) __('filter.export-search'));
        }

        $name = Str::slug(collect([$this->searchbarExportBaseName()])->concat($fields)->push(now()->format('Y-m-d'))->implode(' '));

        return rtrim(Str::limit($name, 100, ''), '-') ?: 'export';
    }

    /** The lines above the column headings: a title, the search text, then each filter and default rule. */
    protected function searchbarExportLines(): array
    {
        return SearchbarExportFilters::describe($this->state);
    }

    /**
     * The export in the order the table shows its rows (the header sort it recorded), as the table sorts a browse
     * (HasSearchbarColumnHeaders::searchbarSortedBy()): the sort's columns replace every order, the unique key breaks
     * ties (the export reads the rows by chunks). A relation.column sort needs the relation joined (Kompo's
     * EloquentQuery): the export keeps the query's own order then (its rows are the same, as strict).
     */
    protected function searchbarExportOrder($query)
    {
        $sort = $this->state->getSort();

        return ($sort ? $this->searchbarSortedBy($query, $sort) : null) ?? $query;
    }
}
