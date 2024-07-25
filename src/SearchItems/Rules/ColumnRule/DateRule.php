<?php

namespace Kompo\Searchbar\SearchItems\Rules\ColumnRule;

use Illuminate\Support\Facades\DB;

class DateRule extends ColumnRule
{
    public function decorateQuery($query)
    {
        return $this->operator->constructQuery($query, DB::raw("DATE_FORMAT({$this->column}, '%Y-%m-%d')"), $this->queryValue());
    }
}    