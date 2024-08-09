<?php

namespace Kompo\Searchbar\Components;

use Kompo\Form;
use Kompo\Searchbar\Components\SearchKomponentUtils;
use Kompo\Searchbar\Components\SearchStateRequestUtils;

class NavbarSearch extends Form
{
    use SearchStateRequestUtils;
    use SearchKomponentUtils;
    
    public $class = 'relative flex-1 flex-row items-center';

    public $id = 'navbar-search';

    public function render()
    {
        $searchPanelLoadingId = 'search-panel-loading' . $this->serviceKey;
        $loadingJs = '() => {searchLoadingOn("'. $searchPanelLoadingId .'")}';

        return _Rows(
            _Rows(
                _Sax('search-normal-1', 24)->class('text-greenmain mt-0 pt-0 px-2'),
                !$this->state->getSearchableInstance()?->searchableName() ? null : _Flex(
                    _RulePill($this->state->getSearchableInstance()?->searchableName(), icon: 'filter'),
                    ...$this->state->getRules()->map(fn($rule, $i) => $rule->render($i)) 
                )->class('gap-2 px-2'),
                _Input()->name('search')->class('navbar-search-input')
                    ->default($this->state->getSearch())
                    ->placeholder('crm.search')
                    ->class('w-full mb-0 text-xl [&>.vlInputWrapper:focus-within]:shadow-none min-w-72')
                    ->inputClass('py-2')
                    ->noAutocomplete()
                    ->onFocus(fn($e) => $e->selfGet('openSearch')->refresh('search-panel'))
                    ->onInput(fn($e) => $e->run($loadingJs) && $e->selfPost('setSearch')->run($loadingJs)->refresh('search-panel')),
                _Spinner()->id($searchPanelLoadingId)->class('relative right-8 hidden'),
            )->class('w-full relative flex-row items-center focus-within:border border-greenmain rounded-lg max-w-6xl overflow-x-auto mini-scroll'),

            _Rows(
                $this->instanciateSearchKomponent(SearchPanel::class),
            )->id('search-panel')->class('fixed top-14 md:top-full left-0 md:absolute z-[110] w-screen md:w-full'),
        )->class('nav-search-box flex-1 pb-[7px]');
    }

    public function js()
    {
        return '<<<javascript
            function searchLoadingOn(id) {
                $("#" + id).removeClass("hidden");
            }

            function searchLoadingOff(id) {
                $("#" + id).addClass("hidden");
            }
        ';
    }
}
