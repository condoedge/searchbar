<?php

namespace Kompo\Searchbar\SearchItems\Stores;

/**
 * A state held in memory for the rest of the request, never read from or written to a session, a row or a link: a
 * table exporting a snapshot of its filters (SearchbarTableExport, see HasSearchbarFilters::searchbarUseSnapshot()).
 * Bound to a service with SearchService::setStore().
 */
class ArraySearchStore extends SearchStore
{
    protected ?SearchState $state = null;

    // As LinkStore: rules serialize their search service, which holds this store; not the state (each stored rule
    // would carry a copy of it). A serialized table rebuilds it from its snapshot.
    public function __sleep()
    {
        return $this->hasContext() ? ['key', 'searchContextService'] : ['key'];
    }

    protected function retrieveState(): ?SearchState
    {
        return $this->state;
    }

    public function storeState($state): void
    {
        $this->state = $state;
    }

    public function clearState(): void
    {
        $this->state = null;
    }
}
