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
                    ->class('w-full mb-0 text-xl [&>.vlInputWrapper:focus-within]:shadow-none min-w-0 md:min-w-48')
                    ->inputClass('py-2')
                    ->noAutocomplete()
                    ->dontSubmitOnEnter()
                    ->onFocus(fn($e) => $e->run('() => {
                        openSearchPanel();
                    }'))
                    ->onEnter(fn($e) => $e->run($loadingJs) && $e->post('searchstate.set-search')->withAllFormValues()->run($loadingJs)->refresh('search-panel'))
                    ->onInput(fn($e) => $e->run($loadingJs) && $e->post('searchstate.set-search')->withAllFormValues()->run($loadingJs)->refresh('search-panel'))
                    ->debounce(900),
                _Spinner()->id($searchPanelLoadingId)->class('relative right-8 hidden searchbar-loading'),
                _Link()->icon('x')->class('text-2xl px-3 text-level1 search-close-btn shrink-0')->style('display:none')
                    ->run('() => { closeSearchPanel(); }'),
            )->class('w-full relative flex-row items-center focus-within:border border-greenmain rounded-lg max-w-6xl overflow-x-auto mini-scroll overflow-y-hidden'),

            _Rows(
                $this->instanciateSearchKomponent(SearchPanel::class),
            )->id('search-panel-container')->class('fixed top-14 md:top-full left-0 md:absolute z-[110] w-screen md:w-full h-[calc(100vh-3.5rem)] md:h-auto max-h-[calc(100vh-3.5rem)] md:max-h-[85vh] overflow-y-auto'),
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

            const isSmallScreen = () => window.innerWidth < 1024;

            window.toggleSearchFilters = () => {
                const panel = $("#search-filters-panel");
                const arrow = $("#search-filters-arrow");
                const isVisible = panel.data("visible") !== false;

                if (isVisible) {
                    panel.css({width: "0", opacity: "0", overflow: "hidden", "margin-right": "-8px"});
                    arrow.css("transform", "rotate(180deg)");
                    panel.data("visible", false);
                } else {
                    panel.css({width: "33.333%", opacity: "1", overflow: "", "margin-right": "0"});
                    arrow.css("transform", "rotate(0deg)");
                    panel.data("visible", true);
                }
            }

            window.switchSearchTab = (tab) => {
                const filtersContent = $("#search-content-filters");
                const favoritesContent = $("#search-content-favorites");
                const filtersTab = $("#search-tab-filters");
                const favoritesTab = $("#search-tab-favorites");

                if (tab === "filters") {
                    filtersContent.show();
                    favoritesContent.hide();
                    filtersTab.addClass("search-tab-active").removeClass("text-gray-400");
                    favoritesTab.removeClass("search-tab-active").addClass("text-gray-400");
                } else {
                    filtersContent.hide();
                    favoritesContent.show();
                    favoritesTab.addClass("search-tab-active").removeClass("text-gray-400");
                    filtersTab.removeClass("search-tab-active").addClass("text-gray-400");
                }
            }

            // Ensure correct initial state: only filters visible
            switchSearchTab("filters");

            window.openSearchPanel = () => {
                const searchPanel = $("#search-panel-container");
                searchPanel.fadeIn(250);
                window.navbar_search_opened = true;

                $(".search-close-btn").show();

                if (isSmallScreen()) {
                    $("#intro-dashboard-help1, #intro-dashboard-help3, #intro-dashboard-user-account").css("display", "none");
                    $("#intro-dashboard-role-switcher").css("display", "none");
                }
            }

            window.closeSearchPanel = (fast = false) => {
                const searchPanel = $("#search-panel-container");

                if (fast) searchPanel.hide();
                else searchPanel.fadeOut(250);

                window.navbar_search_opened = false;

                $(".search-close-btn").hide();

                if (isSmallScreen()) {
                    $("#intro-dashboard-help1, #intro-dashboard-help3, #intro-dashboard-user-account").css("display", "");
                    $("#intro-dashboard-role-switcher").css("display", "");
                }
            }

            // Prevent native form submission on Enter key (causes page reload)
            const navSearchForm = document.getElementById("navbar-search")?.closest("form");
            if (navSearchForm) {
                navSearchForm.addEventListener("submit", (e) => e.preventDefault());
            }

            // Always restore navbar icons on desktop
            if (!isSmallScreen()) {
                $("#intro-dashboard-help1, #intro-dashboard-help3, #intro-dashboard-user-account").css("display", "");
                $("#intro-dashboard-role-switcher").css("display", "");
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
