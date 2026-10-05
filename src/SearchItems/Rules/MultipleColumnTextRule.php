<?php

namespace Kompo\Searchbar\SearchItems\Rules;

use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\OperatorEnum;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;

class MultipleColumnTextRule extends FilterableRule
{    
    use FullTextSearchRuleUtils;

    protected $columns;
    protected OperatorEnum $operator;
    protected $value;

    public function __construct($columns, $operator, $value)
    {
        $this->columns = $columns;
        $this->operator = $operator;
        $this->value = $value;
    }

    public function decorateQuery($query)
    {
        // A pending pill (no value yet) doesn't filter, as for column rules.
        if ($this->isPendingValue() || !$this->columns) {
            return $query;
        }

        // If we have full text search enabled and the operator allows it, we use it. Here we don't derivate the responsibility to the operator.
        if($this->usesFullTextSearch()) {
            return $this->fullSearchQuery($query);
        }

        // "Contains x" in any of the columns, but "does not contain x" in all of them (OR matched almost every row).
        // An empty (NULL) column doesn't contain it either: NULL NOT LIKE ... is NULL, which dropped those rows.
        if ($this->operator->negative()) {
            return $query->where(function ($query) {
                foreach ($this->columns as $column) {
                    $query->where(fn($q) => $q->whereNull($column)
                        ->orWhere(fn($q) => $this->operator->constructQuery($q, $column, $this->queryValue())));
                }
            });
        }

        return $query->where(
            function ($query) {
                foreach ($this->columns as $column) {
                    $query->orWhere(fn($q) => $this->operator->constructQuery($q, $column, $this->queryValue()));
                }
            }
        );
    }

    /** One MATCH over every column (their FULLTEXT index is on that same list); qualified by fullSearchQuery(). */
    protected function fullTextColumns(): array
    {
        return array_values((array) $this->columns);
    }

    public function renderContent()
    {
        // Escaped: _Html renders with v-html and the value is user text.
        return _Html(__('filter.with-values.multiple-search', [
            'columns' => e(collect($this->getFilterable()?->getColumnsOptions())->filter(function($column, $i) {
                return in_array($i, (array) $this->columns);
            })->map(fn($col) => __($col))->implode(', ')),
            'operator' => __($this->operator->label()),
            'value' => e((string) $this->visualValue()),
        ]));
    }

    public function isPendingValue(): bool
    {
        return $this->value === null || (is_string($this->value) && trim($this->value) === '');
    }

    public function queryValue()
    {
        return $this->value;
    }

    public function visualValue()
    {
        if (is_array($this->value)) {
            return implode(', ', $this->value);
        }

        return $this->value;
    }

    public function toArray()
    {
        return [
            $this->columns,
            $this->operator,
            $this->value,
        ];
    }

    /** Also the columns searched (keys of the filter's options, checked again when read back). */
    public function toData(): array
    {
        return parent::toData() + ['columns' => array_values((array) $this->columns)];
    }

    // GETTERS
    public function getColumns()
    {
        return $this->columns;
    }

    public function getOperator()
    {
        return $this->operator;
    }

    public function getValue()
    {
        return $this->value;
    }

    // SETTERS
    public function setValue($value)
    {
        $this->value = $value;
    }

    public function setColumns($columns)
    {
        $this->columns = $columns;
    }

    public function setOperator($operator)
    {
        $this->operator = $operator;
    }
}