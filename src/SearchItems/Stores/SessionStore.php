<?php

namespace Kompo\Searchbar\SearchItems\Stores;

/**
 * A state in the session, as plain data (StateCodec, format v2), under "searchbarState.{key}". Read once while the
 * session holds that same array: a state changed but not stored yet is the one read again in the request, as when the
 * session held the SearchState object itself.
 *
 * Every request writes the whole session back when it ends: one that overlapped a change (the results' lazy load, an
 * option search, another tab) reverts it, and mutate() can't lock anything. The navbar of signed-in users is in
 * DatabaseStore rows when searchbar.store says so (guests stay here).
 *
 * A session of a release before v2 holds that object under "searchState.{key}": imported once (its rules rebuilt
 * through the filterables), then forgotten. The new key keeps a rolled back release from reading an array where it
 * expects an object.
 */
class SessionStore extends SearchStore
{
    const SESSION_PREFIX = 'searchbarState.';
    // Store keys kept in the session whatever searchbar.store says (SearchService::getStore()): a table that doesn't
    // remember its filters (HasSearchbarFilters).
    const KEY_PREFIX = 'ses.';

    public static function handles($key): bool
    {
        return is_string($key) && str_starts_with($key, static::KEY_PREFIX);
    }

    /** A new key of this store for the service $serviceKey (one per page display, as a drawn key). */
    public static function newKey(string $serviceKey): string
    {
        return static::KEY_PREFIX . $serviceKey . '-' . \Illuminate\Support\Str::random(12);
    }

    // The array the session held when the state was read or stored, and that state.
    protected ?array $readData = null;
    protected ?SearchState $readState = null;

    protected function retrieveState(): ?SearchState
    {
        $data = session()->get($this->sessionKey());

        if (!is_array($data)) {
            $data = $this->importLegacyState();
        }

        if (!is_array($data)) {
            return null;
        }

        if ($this->readState && $data === $this->readData) {
            return $this->readState;
        }

        $state = StateCodec::decode($data, $this->getContext());

        if (!$state) {
            // Not a state any more (its entity is no longer a searchable): gone, not decoded (and logged) on every read.
            session()->forget($this->sessionKey());

            return null;
        }

        if (DatabaseStore::rewritesDropped($state)) {
            // Rules that no longer fit (dropped, logged: StateCodec): stored without them once, as a table or a results
            // link is, instead of dropped and logged again on every page (the navbar is on each). Not rules that failed
            // to rebuild: their stored data may be fine.
            $this->storeState($state->setDroppedStoredRules([]));

            return $state;
        }

        $this->readData = $data;

        return $this->readState = $state;
    }

    public function storeState($state): void
    {
        $data = StateCodec::encode($state);

        session([$this->sessionKey() => $data]);

        $this->readData = $data;
        $this->readState = $state;
    }

    public function clearState(): void
    {
        session()->forget([$this->sessionKey(), $this->getKey()]);

        $this->readData = null;
        $this->readState = null;
    }

    protected function sessionKey(): string
    {
        return static::SESSION_PREFIX . $this->key;
    }

    /** The state a session of a release before v2 holds (the object), stored as v2 once, then forgotten. */
    protected function importLegacyState(): ?array
    {
        if (!session()->has($this->getKey())) {
            return null;
        }

        $legacy = session()->pull($this->getKey());
        $state = is_object($legacy) ? StateCodec::decode($legacy, $this->getContext()) : null;

        if (!$state) {
            return null;
        }

        $this->storeState($state);

        return $this->readData;
    }
}
