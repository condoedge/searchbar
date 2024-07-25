<?php

namespace Kompo\Searchbar\SearchItems\Rules\ColumnRule;

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
        // If we have full text search enabled and the operator allows it, we use it. Here we don't derivate the responsibility to the operator.
        if($this->useFullTextSearch()) {
            return $this->fullSearchQuery($query);
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
}