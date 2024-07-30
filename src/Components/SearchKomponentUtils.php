<?php

namespace Kompo\Searchbar\Components;

use Kompo\Searchbar\SearchService;

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
        $this->searchService = searchService($this->getServiceKey())->setStoreKey($this->prop('storeKey'));
        $this->state = stateStore($this->getServiceKey())->getState();
        $this->searchableInstance = $this->state->getSearchableInstance();
    }

    protected function instanciateSearchKomponent(string $komponent, array $props = [])
    {
        $props = array_merge($props, [
            'storeKey' => $this->prop('storeKey') ?? searchService($this->getServiceKey())->getStoreKey(),
        ]);

        return new $komponent($props);
    }

    protected function getServiceKey()
    {
        return property_exists($this, 'serviceKey') ?  $this->serviceKey : SearchService::DEFAULT_KEY;
    }
}