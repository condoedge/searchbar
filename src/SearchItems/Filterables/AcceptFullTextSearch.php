<?php

namespace Kompo\Searchbar\SearchItems\Filterables;

trait AcceptFullTextSearch 
{
    protected $allowFullTextSearch = false;
    
    public function fullTextSearch()
    {
        $this->allowFullTextSearch = true;

        return $this;
    }

    public function hasFullTextSearch()
    {
        return $this->allowFullTextSearch;
    }
}