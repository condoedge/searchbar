<?php

namespace Kompo\Searchbar\SearchItems\Rules\ColumnRule;

use Illuminate\Database\Query\Expression;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumn;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\OperatorEnum;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;
use Kompo\Searchbar\SearchItems\Rules\FullTextSearchRuleUtils;

class ColumnRule extends FilterableRule
{    
    use FullTextSearchRuleUtils;

    protected $column;

    protected OperatorEnum $operator = OperatorEnum::EQUALS_TO;

    protected $value;

    public function __construct($column, $operator, $value)
    {
        $this->column = $column;
        $this->operator = $operator;
        $this->value = $value;
    }

    public function decorateQuery($query)
    {
        if ($this->isPendingValue()) {
            return $query;
        }

        // If we have full text search enabled and the operator allows it, we use it. Here we don't derivate the responsibility to the operator.
        if($this->usesFullTextSearch()) {
            return $this->fullSearchQuery($query);
        }

        if ($digits = $this->searchedDigits($this->operator)) {
            return $this->digitsQuery($query, $this->getParsedColumn(), $this->operator, $digits);
        }

        /*
            Before i use $query->where, but i changed the responsibility to the operator.
            Sometimes the operator needs to use whereIn, whereBetween, etc.
            So: type define rule define operator define query
        */
        return $this->operator->constructQuery($query, $this->getParsedColumn(), $this->queryValue());
    }

    public function renderContent()
    {
        return $this->operator->renderRule($this);
    }

    public function queryValue()
    {
        return $this->operator->constructValue($this->value);
    }

    /**
     * The digit strings this rule searches when its filter compares digits only (FilterableColumn::searchDigitsOnly())
     * under $operator, a text operator: [] otherwise, or when the value has no digit (then compared as typed).
     */
    protected function searchedDigits(OperatorEnum $operator): array
    {
        $textOperators = [OperatorEnum::EQUALS_TO, OperatorEnum::DIFFERENT, OperatorEnum::CONTAINS, OperatorEnum::DOES_NOT_CONTAIN];
        $filterable = in_array($operator, $textOperators, true) ? $this->resolvedFilterable() : null;

        return $filterable instanceof FilterableColumn && $filterable->searchesDigitsOnly()
            ? $filterable->searchDigits($this->fullTextText($this->value))
            : [];
    }

    /**
     * The digits of $column compared with $digits (searchedDigits()): IN for equals, LIKE for contains (only the
     * strings no other one is part of: "5145551234" also finds "+15145551234"). Negations are NOT IN / NOT LIKE; a
     * relation gets here with the positive operator inside its whereDoesntHave. A "%...%" can't use an index anyway:
     * stripping the column costs REGEXP_REPLACE (+30 to 70 ms on the ~300 ms LIKE scan of SISC's 369k phones), and
     * the relation's own keys stay plain columns (its index).
     */
    protected function digitsQuery($query, $column, OperatorEnum $operator, array $digits)
    {
        $sql = $this->digitsOnlySql($query, $column);
        $negative = in_array($operator, [OperatorEnum::DIFFERENT, OperatorEnum::DOES_NOT_CONTAIN], true);

        if (in_array($operator, [OperatorEnum::EQUALS_TO, OperatorEnum::DIFFERENT], true)) {
            return $query->whereRaw($sql . ($negative ? ' NOT IN (' : ' IN (') . implode(', ', array_fill(0, count($digits), '?')) . ')', $digits);
        }

        $patterns = collect($digits)
            ->reject(fn($string) => collect($digits)->contains(fn($other) => $other !== $string && str_contains($string, $other)))
            ->map(fn($string) => '%' . $string . '%')
            ->values()->all();

        $like = $sql . ($negative ? ' NOT LIKE ?' : ' LIKE ?');

        return $query->whereRaw('(' . implode($negative ? ' AND ' : ' OR ', array_fill(0, count($patterns), $like)) . ')', $patterns);
    }

    /** SQL of $column (a name, "table.column", or an Expression) without its non-digits. */
    protected function digitsOnlySql($query, $column): string
    {
        $base = $this->fullTextBaseBuilder($query);
        $sql = $column instanceof Expression ? (string) $column->getValue($base->getGrammar()) : $base->getGrammar()->wrap($column);

        return match ($base->getConnection()->getDriverName()) {
            // MySQL 8, MariaDB 10.0.5+.
            'mysql', 'mariadb' => "REGEXP_REPLACE({$sql}, '[^0-9]', '')",
            'pgsql' => "REGEXP_REPLACE({$sql}, '[^0-9]', '', 'g')",
            // No regex function (SQLite, SQL Server): the characters numbers are written with.
            default => array_reduce([' ', '-', '.', '(', ')', '+', '/'], fn($sql, $char) => "REPLACE({$sql}, '{$char}', '')", $sql),
        };
    }

    /**
     * The rule's filterable, or null when it can't be resolved: without a searchable or a context (a rule built on
     * its own) getFilterable() reads the context's state.
     */
    protected function resolvedFilterable()
    {
        return $this->searchable !== null || $this->hasContext() ? $this->getFilterable() : null;
    }

    public function visualValue()
    {
        return $this->operator->visualValue($this->value);
    }

    public function toArray()
    {
        return [
            $this->column,
            $this->operator,
            $this->value,
        ];
    }

    // GETTERS
    public function getColumn()
    {
        return $this->column;
    }

    public function getRawColumn()
    {
        return $this->isRawColumn() ? substr($this->column, 5) : $this->column;
    }

    public function isRawColumn()
    {
        if (!is_string($this->column)) {
            return false;
        }

        return strpos($this->column, 'RAW::') === 0;
    }

    public function getParsedColumn()
    {
        return $this->isRawColumn() ? \DB::raw($this->getRawColumn()) : $this->column;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function getOperator()
    {
        return $this->operator;
    }

    // SETTERS
    public function setOperator($operator)
    {
        $this->operator = $operator;
    }

    public function setValue($value)
    {
        $this->value = $value;
    }

    public function isPendingValue(): bool
    {
        $isBlank = fn($v) => $v === null || (is_string($v) && trim($v) === '');

        // Also ['', ''] and [null, ''] from an emptied range editor, not only [null] / [null, null].
        return is_array($this->value)
            ? collect($this->value)->every($isBlank)
            : $isBlank($this->value);
    }
}