<?php

namespace Kompo\Searchbar\Components;

use Condoedge\Utils\Kompo\Common\Form;

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

    public $id = 'searchable-options';

    public function render()
    {
        return _Rows(
            searchService()->optionsSearchables(),
        )->class('py-2');
    }
}
