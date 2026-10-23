<?php

namespace Kompo\Searchbar\SearchItems\Filterables\FilterableColumn;

class FilterableTranslatableColumn extends FilterableColumn
{
    public function getRuleInstance($params)
    {
        $column = $this->getColumn();
        $locale = app()->getLocale();

        // JSON_UNQUOTE gives a binary collation: LOWER() on the column only (not the typed value) missed any
        // uppercase letter, and accents always mattered. A case- and accent-insensitive collation handles both.
        return $this->getInputType()->getRuleInstance(array_merge([
            'column' => \DB::raw("JSON_UNQUOTE(JSON_EXTRACT($column, '$.$locale')) COLLATE utf8mb4_unicode_ci"),
            'operator' => $this->getInputType()->defaultOperator(),
        ], $params));
    }
}