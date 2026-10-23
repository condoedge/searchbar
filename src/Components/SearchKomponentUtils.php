<?php

namespace Kompo\Searchbar\Components;

use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumn;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumnTypeEnum;
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

    /**
     * Kompo search-options of a relation filter's select (FilterableColumnTypeEnum::selectElement()): the typed text
     * and the select's ajaxPayload, posted to the komponent rendering the select (navbar, table, custom filters
     * modal, rule form), booted from its own props (storeKey, serviceKey). No field name or form value comes with it:
     * the payload names the filter, resolved on this komponent's entity (a declared filter only, never a class).
     */
    public function searchbarRelationOptions($search = null)
    {
        $key = request('searchbar_filter');
        $filterable = is_string($key) && $key !== '' ? $this->searchableInstance?->filterable($key) : null;
        $entityType = $filterable instanceof FilterableColumn && $filterable->getInputType()->offersOptions()
            ? $filterable->getEntityType()
            : null;

        // Not searchesOnServer(): its size check would run on every keystroke.
        if (!$entityType?->canSearchOnServer()) {
            return [];
        }

        $search = is_string($search) ? trim($search) : '';
        $min = FilterableColumnTypeEnum::serverSearchMinChars();

        // At least one word of the select's minimum, not the whole text: "a b c" passed a minimum of 3 and ran
        // one-letter LIKE scans (~800ms on the people). The select counts the untrimmed text: "ab " comes too.
        if ($min > 0 && !collect(preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY))->contains(fn($word) => mb_strlen($word) >= $min)) {
            return [];
        }

        // A failing host search (scope, searchOn callback) leaves the list empty instead of an "Error 500" alert.
        return rescue(fn() => $entityType->searchOptions($search), [], true);
    }
}