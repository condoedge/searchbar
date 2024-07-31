<?php

namespace Kompo\Searchbar\SearchItems\Stores;

use Kompo\Searchbar\SearchItems\Rules\FilterableRule;
use Kompo\Searchbar\Searchable\Searchable;
use Kompo\Searchbar\SearchItems\SearchItem;

class SearchState extends SearchItem
{
    protected $rules;
    protected $search;
    protected $searchableEntity;
    protected $open = false;


    public function replaceRule($index, $rule)
    {
        $this->rules->put($index, $rule);

        return $this;
    }

    public function removeRule($index)
    {
        $this->rules = $this->rules->filter(fn($r, $i) => $i != $index)->values();

        return $this;
    }

    public function addRule($rule)
    {
        $this->setRules($this->rules->merge([$rule]));

        return $this;
    }

    /** GETTERS */
    public function getRules()
    {
        return $this->rules->map->injectContext($this->searchContextService);
    }

    public function getFilterableRules()
    {
        return $this->getRules()->filter(fn($r) => $r instanceof FilterableRule);
    }

    public function getSearch()
    {
        return $this->search;
    }

    public function getSearchableEntity()
    {
        return $this->searchableEntity;
    }

    public function isOpen()
    {
        return $this->open;
    }

    public function getSearchableInstance(): ?Searchable
    {
        if (!$this->searchableEntity) {
            return null;
        }

        return $this->searchableEntity::createWithContext($this->searchContextService);
    }

    /** SETTERS */
    public function setRules($rules)
    {
        $this->rules = $rules;

        return $this;
    }

    public function setSearch($search)
    {
        $this->search = $search;

        return $this;
    }

    public function setSearchableEntity($searchableEntity)
    {
        $this->searchableEntity = $searchableEntity;

        return $this;
    }

    public function setOpen($open)
    {
        $this->open = $open;

        return $this;
    }

    /** HELPERS */
    public function toArray()
    {
        return [
            'searchableEntity' => $this->searchableEntity,
            'search' => $this->search,
            'rules' => $this->rules->map(fn($r) => serialize($r))->toArray(),
        ];
    }
}