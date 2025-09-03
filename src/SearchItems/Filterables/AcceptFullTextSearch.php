<?php

namespace Kompo\Searchbar\SearchItems\Filterables;

trait AcceptFullTextSearch 
{
    protected $allowFullTextSearch = false;
    protected $usesNgramSearch = false;
    
    public function fullTextSearch($usesNgramSearch = false)
    {
        $this->allowFullTextSearch = true;
        $this->usesNgramSearch = $usesNgramSearch;

        return $this;
    }

    public function usesNgramSearch()
    {
        return $this->usesNgramSearch;
    }

    public function hasFullTextSearch()
    {
        return $this->allowFullTextSearch;
    }
}