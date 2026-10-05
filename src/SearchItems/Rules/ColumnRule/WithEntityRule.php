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
        $filtSpec = $this->getFilterable();
        $entityType = $filtSpec?->getEntityType();

        if (!$entityType) {
            return parent::visualValue();
        }

        if (is_array($this->value)) {
            // One lookup for all the values (a relation label was one find() per value, on every render).
            return collect($entityType->getLabels(array_values($this->value)))->filter()->implode(', ');
        }

        return $entityType->getLabel($this->value) ?: '';
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
