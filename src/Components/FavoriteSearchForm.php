<?php

namespace Kompo\Searchbar\Components;

use Condoedge\Utils\Kompo\Common\Modal;
use Kompo\Searchbar\SearchItems\Stores\DbStore;
use Kompo\Searchbar\SearchService;

/**
 * Saves the current search as a favorite of its user: the navbar's (favorites tab), or a table's (its "Views" menu,
 * HasSearchbarViews): then it saves that table's state (serviceKey prop) and refreshes the table (refresh_id prop).
 */
class FavoriteSearchForm extends Modal
{
    use SearchKomponentUtils;

    protected $_Title = 'filter.favorite-search';
    protected $noHeaderButtons = true;

    protected $serviceKey = SearchService::DEFAULT_KEY;

    public function created()
    {
        // Props set by the server when the form is built (never read from a URL: modals aren't routes).
        $this->serviceKey = $this->prop('serviceKey') ?: $this->serviceKey;

        $this->setSearchProps();
    }

    public function handle()
    {
        $dbStore = DbStore::createWithContext($this->searchService, request('name'));

        $dbStore->storeState($this->state);
    }

    /** name is NOT NULL: an empty one was a 500. */
    public function rules()
    {
        return [
            'name' => 'required|string|max:255',
        ];
    }

    public function body()
    {
        $table = $this->prop('refresh_id');

        return _Rows(
            // Focused as it opens: opened from the navbar's keyboard (Enter on "Save current search"), the name typed
            // went on to the navbar search under the modal, then saved as the favorite.
            _Input('filter.name')->name('name')->focusOnLoad(),

            _FlexEnd(
                // A table's favorite: that table's "Views" menu lists it. The navbar's: only its favorites list
                // changes (the navbar and the custom filters modal were refreshed for nothing).
                _SubmitButton('filter.save')->onSuccess(fn($e) => $e->refresh($table ?: SearchbarIds::FAVORITES)->closeModal()),
            ),
        );
    }
}
