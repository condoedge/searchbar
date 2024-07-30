<?php

namespace Kompo\Searchbar\SearchItems\Stores;

class SessionStore  extends SearchStore
{
    protected function retrieveState(): ?SearchState
    {
        if (!session()->has($this->getKey())) {
            return null;
        }

        return session()->get($this->getKey());
    }

    public function storeState($state): void
    {
        session([$this->getKey() => $state]);
    }

    public function clearState(): void
    {
        session()->forget($this->getKey());
    }
    
    protected function getKey()
    {
        return 'searchState.' . $this->key;
    }
}