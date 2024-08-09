<?php

namespace Kompo\Searchbar\SearchItems\Stores;

use Kompo\Searchbar\SearchItems\Rules\RulesService;
use Kompo\Searchbar\SearchItems\SearchItem;

abstract class SearchStore extends SearchItem
{
    const DEFAULT_KEY = 'default';
    protected $key;

    public function __construct($key = self::DEFAULT_KEY)
    {
        $this->key = $key;
    }

    final public function getState()
    {
        return ($this->retrieveState() ?? $this->getBaseState())->injectContext($this->searchContextService);
    }
    
    abstract protected function retrieveState(): ?SearchState;
    abstract public function storeState(SearchState $state): void;
    abstract public function clearState(): void;
    
    public function setFromRequest($key)
    {
        $data = uncompressArray(request($key));
        $state = $this->getFromStore(fn($key) => $data[$key]);

        $this->storeState($state);
    }

    public function setFromCollection($collection)
    {
        $state = $this->getFromStore(fn($key) => $collection->get($key));

        $this->storeState($state);
    }

    protected function getFromStore($store)
    {
        $state = new SearchState();
        $state->setRules(RulesService::retrieveRulesFromStore(fn($key) => $store($key)))
            ->setSearch($store('search') ?? '')
            ->setSearchableEntity($store('searchableEntity') ?? '')
            ->injectContext($this->searchContextService);

        return $state;
    }

    protected function getBaseState()
    {
        $state = new SearchState();

        $state->setRules(collect())
            ->setSearch('')
            ->setSearchableEntity('');

        return $state;
    }

    protected function getKey()
    {
        return 'searchState.' . $this->key;
    }
}