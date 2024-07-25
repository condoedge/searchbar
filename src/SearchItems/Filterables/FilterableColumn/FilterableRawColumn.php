<?php

namespace Kompo\Searchbar\SearchItems\Filterables\FilterableColumn;

class FilterableRawColumn extends FilterableColumn
{
    public function getRuleInstance($params)
    {
        return $this->getInputType()->getRuleInstance(array_merge([
            'column' => \DB::raw($this->getColumn()),
            'operator' => $this->getInputType()->defaultOperator(),
        ], $params));
    }
}