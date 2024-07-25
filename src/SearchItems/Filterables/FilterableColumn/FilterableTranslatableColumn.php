<?php

namespace Kompo\Searchbar\SearchItems\Filterables\FilterableColumn;

class FilterableTranslatableColumn extends FilterableColumn
{
    public function getRuleInstance($params)
    {
        $column = $this->getColumn();
        $locale = app()->getLocale();

        return $this->getInputType()->getRuleInstance(array_merge([
            'column' => \DB::raw("LOWER(JSON_UNQUOTE(JSON_EXTRACT($column, '$.$locale')))"),
            'operator' => $this->getInputType()->defaultOperator(),
        ], $params));
    }
}