<?php

namespace Kompo\Searchbar\Components;

use Condoedge\Utils\Kompo\Common\Form;
use Kompo\Searchbar\Components\SearchKomponentUtils;
use Kompo\Searchbar\Components\SearchStateRequestUtils;

class NavbarSearch extends Form
{
    use SearchStateRequestUtils;
    use SearchKomponentUtils;
    
    public $class = 'relative flex-1 flex-row items-center';

    public $id = 'navbar-search';

    public function created()
    {
        $this->setSearchProps();

        $this->onLoadOpeningManagment();
    }

    public function render()
    {
        $searchPanelLoadingId = 'search-panel-loading' . $this->serviceKey;
        $loadingJs = '() => {searchLoadingOn("'. $searchPanelLoadingId .'");}';

        return _Rows(
            _Hidden()->name('serviceKey')->default($this->serviceKey),
            _Hidden()->name('storeKey')->default($this->storeKey),
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
                    ->onFocus(fn($e) => $e->run('() => {
                        openSearchPanel();
                    }'))
                    ->onInput(fn($e) => $e->run($loadingJs) && $e->post('searchstate.set-search')->withAllFormValues()->run($loadingJs)->refresh('search-panel'))
                    ->debounce(900),
                _Spinner()->id($searchPanelLoadingId)->class('relative right-8 hidden searchbar-loading'),
            )->class('w-full relative flex-row items-center focus-within:border border-greenmain rounded-lg max-w-6xl overflow-x-auto mini-scroll'),

            _Rows(
                $this->instanciateSearchKomponent(SearchPanel::class),
            )->id('search-panel-container')->class('fixed top-14 md:top-full left-0 md:absolute z-[110] w-screen md:w-full'),
        )->class('nav-search-box flex-1 pb-[7px]');
    }

    public function js()
    {
        return '<<<javascript
            function searchLoadingOn(id) {
                $("#" + id).removeClass("hidden");

                const searchContainer = $("#" + id).closest("#navbar-search").find("#search-panel-container");

                searchContainer.find("a")
                    .attr("disabled", "disabled");

                searchContainer.find(".searchbar-loading").removeClass("hidden");

                searchContainer.find(".entityCountPill").addClass("hidden")
                    .parent().removeClass("bg-warning")
                    .addClass("bg-grayscout bg-opacity-50");
            }

            function searchLoadingOff(id) {
                $("#" + id).addClass("hidden");

                const searchContainer = $("#" + id).closest("#navbar-search").find("#search-panel-container");

                searchContainer.find("a")
                    .removeAttr("disabled");

                searchContainer.find(".searchbar-loading").addClass("hidden");

                searchContainer.find(".entityCountPill").removeClass("hidden");

                searchContainer.find(".entityCountPill").each(function() {
                    const count = $(this).text();
                    if (count == "0" || count == "?") {
                        $(this).parent().removeClass("bg-warning")
                            .addClass("bg-grayscout bg-opacity-50");
                    } else {
                        $(this).parent().removeClass("bg-grayscout bg-opacity-50")
                            .addClass("bg-warning");
                    }
                });
            }
        ';
    }

    protected function onLoadOpeningManagment()
    {
        $this->onLoad(fn($e) => $e->run('() => {
            const opened = window.navbar_search_opened || false;

            window.openSearchPanel = () => {
                const searchPanel = $("#search-panel-container");
                searchPanel.fadeIn(250);
                window.navbar_search_opened = true;
            }

            window.closeSearchPanel = (fast = false) => {
                const searchPanel = $("#search-panel-container");

                if (fast) searchPanel.hide();
                else searchPanel.fadeOut(250);

                window.navbar_search_opened = false;
            }

            if (opened) {
                openSearchPanel();
            } else {
                closeSearchPanel(true);
            }

            document.addEventListener("click", (event) => {
                const navbarSearch = document.getElementById("navbar-search");
                const searchPanel = document.getElementById("search-panel-container");

                const isSearchPanelOpen = !searchPanel.classList.contains("hidden");

                const isTheClickOutsideNavbarSearch = !navbarSearch.contains(event.target) && !event.target.classList.contains("navbar-search-input");

                const isTheClickOnAModal = event.target.closest(".vlMask") !== null;

                if (isSearchPanelOpen && isTheClickOutsideNavbarSearch && !isTheClickOnAModal) {
                    closeSearchPanel();
                    window.navbar_search_opened = false;
                }
            });
        }'));
    }
}
