<?php

namespace Kompo\Searchbar\Components;

use Condoedge\Utils\Kompo\Common\Form;
use Condoedge\Utils\Kompo\Plugins\DebugReload;
use Kompo\Searchbar\SearchItems\Stores\RecentSearches;

/**
 * The navbar's search text and its one sender, the hidden #navbar-search-send link, in their own komponent: pill and
 * chip changes don't re-render it (the focus and the text being typed stay). Refreshed when a change consumes or
 * replaces the text ("Search by" chip, entity picked, favorite loaded: SearchService::refreshTargets()).
 */
class NavbarSearchInput extends Form
{
    use SearchStateRequestUtils;
    use SearchKomponentUtils;

    public $id = SearchbarIds::INPUT;

    public $class = 'flex-1 min-w-0';

    // Not the local debug "reload" link (app.debug) of every komponent: in the navbar's nested komponents it took height in
    // the navbar row and covered the filters column's corner.
    protected $excludePlugins = [DebugReload::class];

    public function created()
    {
        $this->setSearchProps();

        // The text the server has: Enter on it sends nothing (searchbarSendSearch). Each load of this input may carry a
        // new one (a chip consumed the typed text, a favorite was loaded).
        $sentSearch = json_encode((string) $this->state->getSearch(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);

        // A new input: its aria-expanded, and the focus back when Enter on an outlined chip, entity or favorite
        // refreshed it (searchbarKbAfterRefresh).
        $this->onLoad(fn($e) => $e->run('() => { window.searchbarSentSearch = ' . $sentSearch . '; window.searchbarAriaExpanded && searchbarAriaExpanded(); window.searchbarKbAfterRefresh && searchbarKbAfterRefresh(); }'));
    }

    public function render()
    {
        $spinner = json_encode(NavbarSearch::loadingSpinnerId($this->serviceKey));
        // Without the package's send helpers: a tab opened before a deploy keeps its older searchbarClientJs (it
        // registers once per page). The same debounce inline, and the package's lock when that script has it.
        $sendNow = 'document.getElementById("navbar-search-send")?.click()';
        $loadingOn = '(window.searchbarLoadingOn ? searchbarLoadingOn(' . $spinner . ') : (window.searchLoadingOn && searchLoadingOn(' . $spinner . ')))';
        $fallbackQueue = '(' . $loadingOn . ', clearTimeout(window.searchbarFallbackTimer), window.searchbarFallbackTimer = setTimeout(() => ' . $sendNow . ', ' . NavbarSearch::SEARCH_DEBOUNCE_MS . '))';
        $fallbackSend = '(' . $loadingOn . ', clearTimeout(window.searchbarFallbackTimer), ' . $sendNow . ')';

        return _Rows(
            // The one sender of the typed text (searchbarSendSearch clicks it). In this komponent: withAllFormValues()
            // posts its own komponent's fields, the search input. Outside #search-panel-container: searchbarLoadingOn
            // disables the panel's links. The results and the entity counts refresh apart (two parallel requests: the
            // results don't wait for the counts).
            _Link()->id('navbar-search-send')->class('hidden')
                // Where a used search is remembered (searchbarRememberSearch(): Enter, a result opened), while recent
                // searches are on. Absolute, as Kompo's own requests (a relative route drops an app's base path).
                ->when(RecentSearches::enabled(), fn($el) => $el->attr([
                    'data-searchbar-remember' => route('searchstate.remember', $this->searchService->stateParams()),
                ]))
                ->onClick(fn($e) => $e->post('searchstate.set-search', $this->searchService->stateParams())->withAllFormValues()
                    ->refresh(SearchbarIds::RESULTS)->refresh(SearchbarIds::ENTITIES)),
            _Input()->name('search')->class('navbar-search-input')
                ->default($this->state->getSearch())
                ->placeholder('crm.search')
                ->class('w-full mb-0 text-xl [&>.vlInputWrapper:focus-within]:shadow-none min-w-0 md:min-w-48')
                ->inputClass('py-2')
                ->noAutocomplete()
                // A combobox: the arrow keys move over the panel's items, the focus stays here (searchbarClientJs).
                // aria-expanded is set by the panel's open / close, not rendered (a render would reset it).
                ->attr(['role' => 'combobox', 'aria-autocomplete' => 'list', 'aria-controls' => 'search-panel-container'])
                ->dontSubmitOnEnter()
                ->onFocus(fn($e) => $e->run('() => {
                    openSearchPanel();
                }'))
                // One debounce, owned by searchbarClientJs(): Kompo's debounced onInput kept its pending request
                // when Enter sent the same text (two posts, four refreshes). Both only run JS; the link posts.
                ->onInput(fn($e) => $e->run('() => { window.searchbarQueueSearch ? searchbarQueueSearch(' . $spinner . ', ' . NavbarSearch::SEARCH_DEBOUNCE_MS . ') : ' . $fallbackQueue . '; }'))
                ->onEnter(fn($e) => $e->run('() => { window.searchbarSendSearch ? searchbarSendSearch(true, ' . $spinner . ') : ' . $fallbackSend . '; }')),
        );
    }
}
