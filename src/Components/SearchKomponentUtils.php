<?php

namespace Kompo\Searchbar\Components;

trait SearchKomponentUtils
{
    protected $searchService;
    protected $state;
    protected $searchableInstance;

    public function created()
    {
        $this->setSearchProps();
    }

    protected function setSearchProps()
    {
        $this->searchService = searchService()->setStoreKey($this->prop('storeKey'));
        $this->state = stateStore()->getState();
        $this->searchableInstance = $this->state->getSearchableInstance();
    }

    protected function instanciateSearchKomponent(string $komponent, array $props = [])
    {
        $props = array_merge($props, [
            'storeKey' => $this->prop('storeKey') ?? searchService()->getStoreKey(),
        ]);

        return new $komponent($props);
    }
}