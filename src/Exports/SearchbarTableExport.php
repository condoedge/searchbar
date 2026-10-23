<?php

namespace Kompo\Searchbar\Exports;

use Condoedge\Utils\Services\Exports\ComponentToExportableToExcel;
use Illuminate\Contracts\Database\Query\Builder;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * The Excel export of a HasSearchbarFilters table (its getExportableInstance()): the rows the table shows. When the
 * table isn't on its defaults, lines describing its filters come above the column headings (a title, the search text,
 * each filter and default rule, then a blank row); an unfiltered export keeps its headings on row 1.
 *
 * The headings follow the rows: a column whose cells leave the export (exclude-export: the grouped actions' checkbox,
 * row actions) loses its heading too, even when the heading isn't marked, so each heading stays above its column. Read
 * from the first row, and only when it has one cell per heading; not for a child export (exportChildClass). Otherwise
 * the headings are kept as declared: mark the heading exclude-export too. Heading labels are plain text.
 */
class SearchbarTableExport extends ComponentToExportableToExcel
{
    protected array $filterLines;

    // Columns of the table (set by headings()): the filter lines are merged across them.
    protected ?int $columnCount = null;

    public function __construct($component, array $filterLines = [])
    {
        parent::__construct($component);

        $this->filterLines = array_values(array_filter($filterLines, fn($line) => is_string($line) && $line !== ''));

        // Rows, despite the name (ComponentToExportableToExcel::styles()): the headings row.
        $this->boldColumns = [$this->headingRow()];
    }

    /** The row of the column headings: 1, or below the filter lines and a blank row. */
    public function headingRow(): int
    {
        return $this->filterLines ? count($this->filterLines) + 2 : 1;
    }

    public function getFilterLines(): array
    {
        return $this->filterLines;
    }

    public function getComponent()
    {
        return $this->component;
    }

    public function headings(): array
    {
        $headings = array_values(parent::headings());
        $this->columnCount = max(1, count($headings));

        if (!$this->filterLines) {
            return $headings;
        }

        // Several heading rows: every element an array (Maatwebsite's ArrayHelper::ensureMultipleRows()).
        return [...array_map(fn($line) => [$line], $this->filterLines), [''], $headings];
    }

    public function styles($sheet)
    {
        $styles = parent::styles($sheet);

        if (!$this->filterLines) {
            return $styles;
        }

        // Merged across the table (at least 6 columns): autosize ignores merged cells, so a long line doesn't widen the
        // first column. One line each, not wrapped: a merged row doesn't grow to fit wrapped text.
        $last = Coordinate::stringFromColumnIndex(max($this->columnCount ?? 1, 6));

        foreach (array_keys($this->filterLines) as $i) {
            $row = $i + 1;
            $sheet->mergeCells("A{$row}:{$last}{$row}");
            $styles[$row] = [
                'font' => $i === 0 ? ['bold' => true, 'size' => 12] : ['italic' => true],
                'alignment' => ['wrapText' => false],
            ];
        }

        return $styles;
    }

    protected function parseHeaders($ths)
    {
        $ths = collect($ths)->filter()->values();
        // A child export (exportChildClass) renders other rows: its headings stay as declared.
        $excludedCells = $this->getExportChildClass() ? [] : $this->excludedCellPositions($ths->count());

        return $ths
            ->map(fn($th, $i) => $this->isExcludedHeader($th) || isset($excludedCells[$i]) ? null : $this->plainLabel($th->label ?? ''))
            ->filter(fn($label) => $label !== null)
            ->values()->all();
    }

    /** A label as text (a heading may hold HTML: an icon, a link). */
    protected function plainLabel($label): string
    {
        $text = trim(html_entity_decode(strip_tags((string) $label), ENT_QUOTES | ENT_HTML5));

        return $text !== '' ? $text : '-';
    }

    /**
     * Positions of the cells the rows leave out (as formatItemToExport() does), when the first row has one cell per
     * heading; else none (the headings are then taken as declared).
     */
    protected function excludedCellPositions(int $headingCount): array
    {
        $item = rescue(fn() => $this->firstItem(), null, false);
        $row = $item ? rescue(fn() => $this->component->render($item), null, false) : null;
        $cells = is_object($row) && property_exists($row, 'elements') ? collect($row->elements)->filter()->values() : collect();

        if (!$headingCount || $cells->count() !== $headingCount) {
            return [];
        }

        return $cells->filter(fn($cell) => property_exists($cell, 'class') && str_contains((string) $cell->class, 'exclude-export'))
            ->keys()->flip()->all();
    }

    protected function firstItem()
    {
        $query = $this->component->query();

        return $query instanceof Builder ? (clone $query)->first() : collect($query)->first();
    }
}
