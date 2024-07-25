<?php

namespace Kompo\Searchbar\SearchItems\Rules\ColumnRule;

abstract class WithEntityRule extends ColumnRule
{
    public function __construct($column, $operator, $value)
    {
        $this->column = $column;
        $this->operator = $operator;
        $this->value = $value;
    }

    public function visualValue()
    {
        /**
         * @var \Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumn $filtSpec
         */
        $filtSpec = $this->getFilterable($this->getState()->getSearchableInstance());

        if (is_array($this->value)) {
            return collect($this->value)->map(function ($value) use($filtSpec) {
                return $filtSpec->getEntityType()->from($value)->label();
            })->implode(', ');
        }

        return $filtSpec->getEntityType()->from($this->value)->label();
    }

    public function toArray()
    {
        return [
            $this->column,
            $this->operator,
            $this->value,
        ];
    }
}