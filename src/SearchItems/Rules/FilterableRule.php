<?php

namespace Kompo\Searchbar\SearchItems\Rules;

use Kompo\Searchbar\SearchItems\Rules\Rule;
use Kompo\Searchbar\Searchable\Searchable;

/**
 * I want to keep the rule base model separeted from the searchable, 
 * so i created a new abstract class FilterableRule, to give it the implementation of searchable filterables.
 */
abstract class FilterableRule extends Rule
{
    protected $searchable;
    protected $keyReference;
    protected $rule;

    public function getFilterable(Searchable $searchable = null)
    {
        $searchable = $searchable ?? $this->searchable ?? $this->getState()->getSearchableInstance();

        if (!$searchable) {
            return null;
        }
    
        return $searchable->filterable($this->keyReference)->setAssignedRule($this);
    }

    public function setKeyReference($keyReference)
    {
        $this->keyReference = $keyReference;

        return $this;
    }

    public function getKeyReference()
    {
        return $this->keyReference;
    }

    public function setSearchable($searchable)
    {
        $this->searchable = $searchable;

        if (is_string($this->searchable)) {
            $this->searchable = (new $this->searchable)->injectContext($this->searchContextService);
        }

        return $this;
    }
}