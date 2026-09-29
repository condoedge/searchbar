<?php

namespace Kompo\Searchbar\Components;

use Condoedge\Utils\Kompo\Common\Form;
use Kompo\Searchbar\SearchService;

/**
 * The list of searchable entities with their result counts, shown in the
 * filters panel when the user hasn't selected a specific entity.
 *
 * It lives in its own komponent so the navbar search can refresh it on input
 * (-> counts recompute) without re-rendering the whole SearchPanel.
 */
class SearchableOptions extends Form
{
    use SearchStateRequestUtils;
    use SearchKomponentUtils;

    public $id = SearchbarIds::ENTITIES;

    public function created()
    {
        $this->setSearchProps();

        // Its rows are keyboard items: the one outlined is outlined again once the counts refreshed (same order).
        // Counts landing while newer text is on its way bring new rows: locked again (searchbarRelockTyping), a row
        // picked then would build its default rule from the older text.
        $this->onLoad(fn($e) => $e->run('() => { window.searchbarRelockTyping && searchbarRelockTyping(); window.searchbarKbAfterRefresh && searchbarKbAfterRefresh(); }'));
    }

    public function render()
    {
        return _Rows(
            // Below the minimum every count is "?", as with no text: the list says why.
            $this->searchService->searchTextTooShort($this->state->getSearch() ?? '')
                ? _Html(__('navbar.search.type-min-chars', ['min' => SearchService::minSearchChars()]))->class('text-xs text-gray-500 px-5 py-1')
                : null,
            // Spread: Kompo takes a collection as the elements only when it is the one argument.
            ...$this->searchService->optionsSearchables()->values(),
        )->class('py-2');
    }
}
