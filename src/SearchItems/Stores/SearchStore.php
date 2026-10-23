<?php

namespace Kompo\Searchbar\SearchItems\Stores;

use Kompo\Searchbar\SearchItems\SearchItem;

abstract class SearchStore extends SearchItem
{
    const DEFAULT_KEY = 'default';
    protected $key;

    public function __construct($key = self::DEFAULT_KEY)
    {
        $this->key = $key;
    }

    // Set while mutate() runs its change (see there).
    protected bool $mutating = false;

    final public function getState()
    {
        return ($this->retrieveState() ?? $this->getBaseState())->injectContext($this->searchContextService);
    }

    abstract protected function retrieveState(): ?SearchState;
    abstract public function storeState(SearchState $state): void;
    abstract public function clearState(): void;

    /**
     * Read-modify-write without lost updates: every change of a state goes through here (the searchstate/* actions,
     * the komponents' writes). $change gets the latest stored state, read again under the store's lock, then that
     * state is stored, unless $change returns false (nothing to store). A state it returns is stored instead (a whole
     * state replaced: a favorite, a recent search, a shared view). Returns the state stored.
     *
     * A database store (DatabaseStore, TableStore, LinkStore) locks its row from that read to the write (SELECT ... FOR
     * UPDATE in a transaction): two requests changing one state run one after the other, the second on the first's
     * result. A pill applied while an option search runs, two chips clicked fast, two tabs no longer revert each other.
     * Changes name their rules by id (never a position): applied to a newer state, they still hit their rule.
     *
     * $change may run again (a first write racing another one is retried on that one's row): it changes the state only,
     * no other side effect. Within a mutate() of this store, a nested one runs on that state, without reading again.
     * The session can't be locked (SessionStore): Laravel writes the whole session back at the end of every request.
     */
    public function mutate(callable $change): SearchState
    {
        if ($this->mutating) {
            return $this->applyChange($change);
        }

        return $this->withLock(function () use ($change) {
            $this->mutating = true;

            try {
                $this->forgetLoaded();

                return $this->applyChange($change);
            } finally {
                $this->mutating = false;
            }
        });
    }

    protected function applyChange(callable $change): SearchState
    {
        $state = $this->getState();
        $result = $change($state);

        if ($result === false) {
            return $state;
        }

        $state = $result instanceof SearchState ? $result->injectContext($this->searchContextService) : $state;
        $this->storeState($state);

        return $state;
    }

    /** Runs $callback holding this store's lock (none by default). */
    protected function withLock(callable $callback)
    {
        return $callback();
    }

    /** Forgets the state read in this request: the next read goes to the stored one. */
    protected function forgetLoaded(): void
    {
    }

    /**
     * A state rebuilt from stored data (SearchState::toArray() / toStorageArray()), bound to
     * this store's service; not stored. A copy to change before storing it (a table opening a shared view). Null when
     * it isn't a state (StateCodec::decode()).
     */
    public function stateFromArray(array $data): ?SearchState
    {
        return StateCodec::decode($data, $this->searchContextService);
    }

    /**
     * The state stored in a search_states row (a link, a favorite, a recent search) as v2 plain data, or null when it
     * isn't a state. Nothing is instantiated. $withRules
     * false: only its entity, search text and list data (StateCodec::header()), for lists.
     */
    public static function decodeRow($row, bool $withRules = true): ?array
    {
        if (!$row) {
            return null;
        }

        return $withRules ? StateCodec::read((string) $row->raw_state) : StateCodec::header((string) $row->raw_state);
    }

    /** Seeds and stores the state from stored data (StateCodec::read()); an empty state when it isn't one. */
    public function setFromCollection($collection)
    {
        $state = StateCodec::decode($collection, $this->searchContextService);

        $this->storeState($state ?? $this->getBaseState()->injectContext($this->searchContextService));
    }

    protected function getBaseState()
    {
        $state = new SearchState();

        $state->setRules(collect())
            ->setSearch('')
            ->setSearchableEntity('');

        return $state;
    }

}