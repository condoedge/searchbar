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
        // If we have full text search enabled and the operator allows it, we use it. Here we don't derivate the responsibility to the operator.
        if($this->useFullTextSearch()) {
            return $this->fullSearchQuery($query);
        }

        return $query->where(
            function ($query) {
                foreach ($this->columns as $column) {
                    $query->orWhere(fn($q) => $this->operator->constructQuery($q, $column, $this->queryValue()));
                }
            }
        );
    }

    protected function getColumnForFullTextSearch()
    {
        return collect($this->columns)->implode(', ');
    }

    public function renderContent()
    {
        return _Html(__('filter.with-values.multiple-search', [
            'columns' => collect($this->getFilterable()->getColumnsOptions())->filter(function($column, $i) {
                return in_array($i, $this->columns);
            })->map(fn($col) => _($col))->implode(', '),
            'operator' => __($this->operator->label()),
            'value' => $this->value,
        ]));
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