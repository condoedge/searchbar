<?php

namespace Kompo\Searchbar\SearchItems\Stores;

use Kompo\Searchbar\Facades\SearchStateModel;

class DbStore  extends SearchStore
{
    protected function retrieveState(): ?SearchState
    {
        $searchStateModel = SearchStateModel::find($this->key);

        $stateArray = unserialize($searchStateModel->raw_state);

        return $this->getFromStore(fn($key) => $stateArray[$key]);
    }

    public function storeState($state): void
    {
        $model = new (SearchStateModel::getClass());
        $model->name = $this->key;
        $model->raw_state = serialize($state->toArray());
        $model->user_id = auth()->id();
        $model->save();

        $this->key = $model->id;
    }

    public function clearState(): void
    {
        SearchStateModel::destroy($this->key);
    }
}