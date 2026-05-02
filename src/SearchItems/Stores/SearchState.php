<?php

namespace Kompo\Searchbar\SearchItems\Stores;

use Kompo\Searchbar\SearchItems\Rules\FilterableRule;
use Kompo\Searchbar\Searchable\Searchable;
use Kompo\Searchbar\SearchItems\Rules\PremadeRuleWrapper;
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

    public function addRuleAtFirst($rule)
    {
        $this->setRules(collect([$rule])->merge($this->rules));

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

    public function getDefaultRules()
    {
        return $this->getRules()->filter(fn($r) => $r instanceof PremadeRuleWrapper);
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

    /**
     * WAS ENABLED FOR THE NEXT PURPOSE:
     * To have a default entity to show in the results panel even if the user didn't select any, 
     * we needed to use this method to return an instance of that default entity when we don't have any selected searchable entity.
     * 
     * But since it's a weird behaviour i set it as configuration that can be disabled
     * And just being more specific but still abstract, we just call it in results panel
     * but panel doesn't know about the specific implementation
     */
    public function getSearchableInstanceForResultsPanel(): ?Searchable
    {
        $searchableEntity = $this->getSearchableEntity();

        if (config('searchbar.default-results-entity') && !$searchableEntity) {
            $searchableEntity = new (config('searchbar.default-results-entity'));
        }

        if (!$searchableEntity) {
            return null;
        }

        return $searchableEntity::createWithContext($this->searchContextService);
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