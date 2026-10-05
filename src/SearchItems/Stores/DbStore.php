<?php

namespace Kompo\Searchbar\SearchItems\Stores;

use Illuminate\Support\Facades\Log;
use Kompo\Searchbar\Facades\SearchStateModel;

/**
 * A favorite (search_states row of type USER, or GLOBAL): a snapshot of a search (SearchState::toArray()), stored as
 * v2 JSON (StateCodec), read in any format.
 */
class DbStore  extends SearchStore
{
    protected function retrieveState(): ?SearchState
    {
        $searchStateModel = SearchStateModel::find($this->key);

        if (!$searchStateModel) {
            return null;
        }

        // Not an empty state: loading a favorite that isn't a state any more (its entity no longer a searchable) must
        // not wipe the search it replaces (FavoritesSearches::loadFavorite() keeps it and logs).
        return StateCodec::decode((string) $searchStateModel->raw_state, $this->getContext())
            ?? throw new \UnexpectedValueException("The favorite {$searchStateModel->id} is not a search state any more.");
    }

    public function storeState($state): void
    {
        $raw = StateCodec::toJson($state->toArray());

        // Never read back over the cap (StateCodec): not saved.
        if (strlen($raw) > StateCodec::maxBytes()) {
            Log::warning('searchbar.state_too_large', ['key' => 'favorite', 'user_id' => auth()->id(), 'bytes' => strlen($raw)]);

            return;
        }

        $model = new (SearchStateModel::getClass());
        $model->name = $this->key;
        $model->raw_state = $raw;
        $model->user_id = auth()->id();
        $model->save();

        $this->key = $model->id;
    }

    public function clearState(): void
    {
        SearchStateModel::destroy($this->key);
    }
}
