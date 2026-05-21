<?php

namespace Kompo\Searchbar\Components;

use Kompo\Searchbar\SearchService;

trait SearchKomponentUtils
{
    protected $searchService;
    protected $state;
    protected $searchableInstance;
    protected $storeKey;

    public function created()
    {
        $this->setSearchProps();
    }

    protected function setSearchProps()
    {
        if (!$this->prop('storeKey')) {
            searchService($this->getServiceKey())->setStoreKey();
            $this->store(['storeKey' => searchService($this->getServiceKey())->getStoreKey()]);
        }

        $this->storeKey = $this->prop('storeKey');

        $this->searchService = searchService($this->getServiceKey())->setStoreKey($this->prop('storeKey'));
        $this->state = stateStore($this->getServiceKey())->getState();
        // Default-aware: consumers of this property (RuleForms, ConfirmMultiDeleteModal)
        // operate on the results-panel entity, which falls back to the default entity
        // when the user hasn't explicitly selected one.
        $this->searchableInstance = $this->state->getSearchableInstanceForResultsPanel();
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