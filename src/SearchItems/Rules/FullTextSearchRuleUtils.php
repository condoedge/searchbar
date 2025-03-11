<?php

namespace Kompo\Searchbar\SearchItems\Rules;

use  Illuminate\Database\Query\Expression;

trait FullTextSearchRuleUtils
{
    protected function fullSearchQuery($query)
    {
        $value = $this->constructValueForFullTextSearch($this->value);

        if(!$value) {
            return $query;
        }

        $column = $this->getColumnForFullTextSearch();

        return $query->whereRaw("MATCH(". $column .") AGAINST(? IN BOOLEAN MODE)", [$value]);
    }

    protected function getColumnForFullTextSearch()
    {
        return $this->column instanceof Expression ? $this->column->getValue(\DB::getQueryGrammar()) : $this->column;
    }

    protected function constructValueForFullTextSearch($value)
    {
        return collect(explode(' ', $value))->map(function($word) {
            return '+' . $word . '*';
        })->implode(' ');
    }

    protected function useFullTextSearch()
    {
        return $this->getFilterable() && method_exists($this->getFilterable(), 'hasFullTextSearch') && 
            $this->getFilterable()->hasFullTextSearch() && 
            (!property_exists($this, 'operator') || $this->operator->acceptFullTextSearch());
    }

}