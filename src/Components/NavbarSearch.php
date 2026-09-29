<?php

namespace Kompo\Searchbar\Components;

use Condoedge\Utils\Kompo\Common\Form;
use Kompo\Core\KompoAction;
use Kompo\Searchbar\Components\SearchKomponentUtils;
use Kompo\Searchbar\Components\SearchStateRequestUtils;

class NavbarSearch extends Form
{
    use SearchStateRequestUtils;
    use SearchKomponentUtils;

    public $class = 'relative flex-1 flex-row items-center';

    public $id = SearchbarIds::NAVBAR;

    /** Typing waits this long before the text is searched (Enter searches at once). */
    public const SEARCH_DEBOUNCE_MS = 600;

    public function created()
    {
        $this->setSearchProps();

        $this->onLoadOpeningManagment();
    }

    /** The navbar's loading spinner (typed text being searched): searchbarLoadingOn/Off and the host's helpers. */
    public static function loadingSpinnerId(string $serviceKey): string
    {
        return 'search-panel-loading' . $serviceKey;
    }

    /**
     * The shell: the pills, the input and the panel are komponents of their own, refreshed apart (SearchService::
     * refreshTargets()); the shell itself only when a favorite replaces everything.
     */
    public function render()
    {
        // A page rendered before the split: its pill editor's relation select searches its options here (the pills
        // were this komponent's). Answered by searchbarRelationOptions() without building the navbar.
        if (KompoAction::is('search-options')) {
            return _Rows();
        }

        return _Rows(
            _Hidden()->onLoad(fn($e) => $e->run(searchbarClientJs())),
            _Rows(
                _Sax('search-normal-1', 24)->class('text-greenmain mt-0 pt-0 px-2'),
                $this->instanciateSearchKomponent(NavbarSearchPills::class),
                $this->instanciateSearchKomponent(NavbarSearchInput::class),
                _Spinner()->id(static::loadingSpinnerId($this->serviceKey))->class('relative right-8 hidden searchbar-loading'),
                _Link()->icon('x')->class('text-2xl px-3 text-level1 search-close-btn shrink-0')->style('display:none')
                    ->run('() => { closeSearchPanel(); }'),
            // A ring doesn't take space: the 1px focus border shifted the whole bar.
            )->class('w-full relative flex-row items-center rounded-lg max-w-6xl overflow-x-auto mini-scroll overflow-y-hidden transition-shadow focus-within:ring-2 ring-level3'),

            _Rows(
                $this->instanciateSearchKomponent(SearchPanel::class),
            )->id('search-panel-container')->class('fixed top-14 md:top-full left-0 md:absolute z-[110] w-screen md:w-full h-[calc(100vh-3.5rem)] md:h-auto max-h-[calc(100vh-3.5rem)] md:max-h-[85vh]'),
        )->class('nav-search-box flex-1 pb-[7px]');
    }

    /**
     * Runs on each load of the navbar search: the page, and a favorite loaded (the only change refreshing the whole
     * navbar; the others refresh its pills, input, panel or panel parts). Document level listeners are registered once
     * (they piled up, one more per load), and the panel's UI state (tab, filters column) is kept in window.* instead of
     * being reset. The window.* helpers defined here are also called by SearchPanel's load (searchbarPanelLoaded).
     *
     * Below md the panel's columns stack (filters under the results, the whole panel scrolls, and keeps its scroll
     * across the refreshes; a tab or the unfold arrow scrolls to the filters). Host contract: each
     * open / close (and each load) dispatches document "searchbar:panel" {open, smallScreen: below 1024px} and toggles
     * html.searchbar-panel-open; the host hides its own navbar items from there (SISC: layouts/app-scripts), the
     * package no longer touches host ids (#intro-dashboard-*). While the panel is closed the pills fold into one chip
     * counting the applied filters (searchbarSetCompact, NavbarSearchPills); open below md they get a row of their own
     * under the navbar (after it in the DOM too), the input keeps the navbar's row (searchbarPlacePills), and the panel
     * closes at once there (fading, it jumped up by the row's height).
     *
     * Keyboard (searchbarClientJs()): the input is a combobox whose aria-expanded follows the panel (so do the chip's
     * and the retract arrow's); the outlined item goes when the panel closes, the tab changes or the filters column
     * retracts (searchbarKbReset).
     */
    protected function onLoadOpeningManagment()
    {
        $this->onLoad(fn($e) => $e->run('() => {
            const opened = window.navbar_search_opened || false;

            // matchMedia, as the CSS breakpoints (innerWidth is the fallback).
            const below = (px) => window.matchMedia ? !window.matchMedia("(min-width: " + px + "px)").matches : window.innerWidth < px;
            // Below 1024px the open panel takes the navbar width: the host may hide its own items (searchbar:panel).
            const isSmallScreen = () => below(1024);
            // Below md SearchPanel stacks its columns (flex-col md:flex-row): the filters go under the results.
            const isStacked = () => below(768);

            // Stacked, the fixed container (top-14, 100vh tall) starts under the pills\' own row (searchbarPlacePills)
            // and ends at the visible bottom: dvh where supported (100vh ended under a phone browser toolbar, the end
            // of the panel was out of reach). Side by side: its classes (md:absolute md:top-full md:h-auto).
            const stackedBox = () => {
                if (!isStacked()) return {top: "", height: "", "max-height": ""};
                const row = window.searchbarPillsRow || 0;
                const dvh = !!(window.CSS && CSS.supports && CSS.supports("height", "100dvh"));
                const above = row ? row + "px + 3.5rem" : "3.5rem";
                const height = dvh || row ? "calc(" + (dvh ? "100dvh" : "100vh") + " - " + (row ? "(" + above + ")" : above) + ")" : "";
                return {top: row ? "calc(" + above + ")" : "", height: height, "max-height": height};
            };

            // The combobox (NavbarSearchInput renders its role): expanded while the panel is open. Set here, not
            // rendered: a refreshed input gets it again from its load.
            window.searchbarAriaExpanded = () => {
                document.querySelector("#navbar-search .navbar-search-input input")?.setAttribute("aria-expanded", window.navbar_search_opened ? "true" : "false");
            }

            window.setSearchFiltersVisible = (visible) => {
                window.searchFiltersVisible = visible;
                // A retracted column\'s items can\'t be the keyboard\'s (searchbarClientJs).
                if (!visible) window.searchbarKbReset && searchbarKbReset("filters");
                $("#search-filters-arrow").attr("aria-expanded", visible ? "true" : "false");

                const stacked = isStacked();
                const main = document.getElementById("search-panel-main");

                // Width, border side and flex have no compiled md: classes: set here, and again when md is crossed.
                // Stacked, the retract arrow folds the filters (no width animation) and points down / up.
                $("#search-filters-panel").css(stacked
                    ? {width: "100%", opacity: "1", overflow: "", "margin-right": "0", display: visible ? "" : "none", "border-left-width": "0", "border-top-width": "1px"}
                    : Object.assign({display: "", "border-left-width": "", "border-top-width": ""}, visible
                        ? {width: "33.333%", opacity: "1", overflow: "", "margin-right": "0"}
                        : {width: "0", opacity: "0", overflow: "hidden", "margin-right": "-8px"}));
                $("#search-filters-arrow").css("transform", stacked
                    ? (visible ? "rotate(90deg)" : "rotate(-90deg)")
                    : (visible ? "rotate(0deg)" : "rotate(180deg)"));

                // Stacked, the fixed container scrolls the whole panel (the results column, 95vh capped, below the
                // navbar and the top bar ran off the screen): the results keep their height, the filters follow,
                // the top bar (tabs, arrow) sticks. The results no longer slide under the top bar (-mt-8).
                // overflow-x: a classic scrollbar narrowed the container (a horizontal one appeared). White: below a
                // folded or short panel the page showed through, and a tap there did nothing (inside the navbar).
                // Its top and height: stackedBox().
                $("#search-panel-container").css(Object.assign(stacked
                    ? {"overflow-y": "auto", "overflow-x": "hidden", "background-color": "#fff"}
                    : {"overflow-y": "", "overflow-x": "", "background-color": ""}, stackedBox()));
                $("#search-panel-top").css(stacked ? {position: "sticky", top: "0", "z-index": "10"} : {position: "", top: "", "z-index": ""});
                $("#search-results-column").css(stacked ? {flex: "none", "overflow-y": "visible", "margin-top": "0"} : {flex: "", "overflow-y": "", "margin-top": ""});
                if (main) {
                    if (main.dataset.searchbarMaxHeight === undefined) main.dataset.searchbarMaxHeight = main.style.maxHeight; // the cap SearchPanel sets
                    main.style.maxHeight = stacked ? "none" : main.dataset.searchbarMaxHeight;
                }
            }

            // The host is told when the panel opens or closes, on every load, and when 1024px is crossed: document
            // event "searchbar:panel", detail {open, smallScreen}; <html> has .searchbar-panel-open while open. The
            // package no longer hides host ids itself (a host hides its navbar items from its listener).
            window.searchbarEmitPanel = () => {
                const open = !!window.navbar_search_opened;
                document.documentElement.classList.toggle("searchbar-panel-open", open);
                document.dispatchEvent(new CustomEvent("searchbar:panel", {detail: {open: open, smallScreen: isSmallScreen()}}));
            }

            // Stacked, the filters column starts under the results, near the bottom of a phone screen: a tab or the
            // unfold arrow changed content out of sight. The column is scrolled to, under the sticky top bar.
            window.searchbarScrollToFilters = () => {
                const container = document.getElementById("search-panel-container");
                const filters = document.getElementById("search-filters-panel");
                if (!container || !filters || !isStacked() || !window.navbar_search_opened) return;

                const top = document.getElementById("search-panel-top");
                const target = container.scrollTop + filters.getBoundingClientRect().top - container.getBoundingClientRect().top - (top ? top.offsetHeight : 0);
                const reduced = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
                container.scrollTo ? container.scrollTo({top: target, behavior: reduced ? "auto" : "smooth"}) : (container.scrollTop = target);
            }

            window.toggleSearchFilters = () => {
                setSearchFiltersVisible(window.searchFiltersVisible === false);
                if (window.searchFiltersVisible) searchbarScrollToFilters();
            }

            // fromUser: a tab link (not a load re-applying the tab). The tapped tab is shown: a retracted / folded
            // column unfolds (a tab changed nothing visible).
            window.switchSearchTab = (tab, fromUser = false) => {
                if (window.searchActiveTab !== tab) window.searchbarKbReset && searchbarKbReset("filters");
                window.searchActiveTab = tab;

                const isFilters = tab === "filters";
                const active = "bg-white shadow-sm text-greenmain";

                $("#search-content-filters").toggle(isFilters);
                $("#search-content-favorites").toggle(!isFilters);
                $("#search-tab-filters").toggleClass(active, isFilters).toggleClass("text-gray-400", !isFilters);
                $("#search-tab-favorites").toggleClass(active, !isFilters).toggleClass("text-gray-400", isFilters);

                if (fromUser) {
                    if (window.searchFiltersVisible === false) setSearchFiltersVisible(true);
                    searchbarScrollToFilters();
                }
            }

            // Stacked, the filters being used went back under the results, off the screen, when the results above them
            // reloaded (skeleton first: every chip, toggle and pill refreshes them), when the panel reloaded (an entity
            // picked, Back) and when the navbar did (a favorite: a new container, scrolled to the top).
            // The scroll is kept (window.searchbarPanelScroll, until the panel closes) and put back: relative to the
            // filters column while its top is in view or above, else as is (deep in the results).
            const panelScroll = () => {
                const container = document.getElementById("search-panel-container");
                const filters = document.getElementById("search-filters-panel");
                if (!container || !filters || !isStacked() || !window.navbar_search_opened) return null;

                return {container: container, filtersY: filters.getBoundingClientRect().top - container.getBoundingClientRect().top};
            };

            window.searchbarSavePanelScroll = () => {
                const now = panelScroll();
                if (!now) return;

                const anchored = window.searchFiltersVisible !== false && now.filtersY < now.container.clientHeight;
                window.searchbarPanelScroll = {top: now.container.scrollTop, filtersY: anchored ? now.filtersY : null};
            }

            window.searchbarRestorePanelScroll = () => {
                const now = panelScroll();
                const saved = window.searchbarPanelScroll;
                if (!now || !saved) return;

                const target = saved.filtersY === null ? saved.top : now.container.scrollTop + now.filtersY - saved.filtersY;
                // Already there: no write (it would stop a phone\'s momentum scroll).
                if (Math.abs(target - now.container.scrollTop) < 1) return;

                now.container.scrollTop = target;
                // Its scroll event is not the user\'s: the saved scroll stays (the skeleton may have clamped this one).
                window.searchbarPanelScrollSet = now.container.scrollTop;
            }

            switchSearchTab(window.searchActiveTab || "filters");
            setSearchFiltersVisible(window.searchFiltersVisible !== false);

            // The reloaded navbar (a favorite) replaced what its action locked (searchbarBusy).
            window.searchbarUnlock && searchbarUnlock();

            // Closed, the pills fold into one chip (NavbarSearchPills\' .searchbar-filters-count, "Persons 2"): in the
            // scrolling bar they pushed the input aside and scrolled out of sight. Open, the pills are back. Also run
            // by the pills\' own load: their refresh brings the server\'s markup (pills shown, chip hidden).
            window.searchbarSetCompact = () => {
                const root = document.getElementById("navbar-search");
                if (!root) return;
                // Never with a pill editor in them (a pending pill stored with the state, or landing after the panel
                // closed): folded, it was out of sight yet applied by the next click anywhere (the chip\'s first click
                // removed the pill instead of opening the panel) and cancelled by Esc. The pills\' next load folds.
                const fold = !window.navbar_search_opened && !root.querySelector(".search-rule-pills .inline-filter-editor");
                const pills = [...root.querySelectorAll(".search-rule-pills")];
                const chips = [...root.querySelectorAll(".searchbar-filters-count")];
                // The keyboard focus on a pill (Esc closed the panel) went to the page with it: the chip stands for
                // the pills, it gets the focus (focused, it opens nothing).
                const focusedPill = fold && pills.some((n) => !n.classList.contains("hidden") && n.contains(document.activeElement));
                pills.forEach((n) => n.classList.toggle("hidden", fold));
                chips.forEach((n) => { n.classList.toggle("hidden", !fold); n.setAttribute("aria-expanded", window.navbar_search_opened ? "true" : "false"); });
                if (focusedPill && chips[0]) chips[0].focus();
                searchbarPlacePills();
            }

            // Below md, while the panel is open, the pills (NavbarSearchPills) get a row of their own under the navbar,
            // full width, and the panel starts under it: in the navbar\'s row they took its width and squeezed the input
            // to about 24px, past the screen\'s edge (typing scrolled it back, unreadable). Fixed like the container
            // (top-14), out of the row: the input keeps it all, the navbar keeps its height. Compiled classes only; the
            // row scrolls sideways, and clips as the navbar\'s row did. Closed (the chip, a pill editor left open) or
            // from md, the pills are back in the navbar\'s row. Run with searchbarSetCompact (open, close, the pills\'
            // loads) and when md is crossed; the row\'s height (a pill editor, its scrollbar) is followed.
            const PILLS_ROW = ["fixed", "left-0", "top-14", "w-full", "z-[110]", "bg-white", "border-b", "border-level4", "py-1", "overflow-x-auto", "overflow-y-hidden", "mini-scroll"];
            // On their own row the pills also come after the navbar\'s row in the DOM (its last item, the close ✕), as
            // on the screen: before the input, the Tab and screen reader order read them first, and Shift+Tab from the
            // input went to the last pill\'s ✕, under it. Otherwise back before the input, as rendered. A moved node
            // loses the focus: it is given back (the chip Esc gave it to, a pill).
            const placePillsInDom = (pills, own) => {
                const input = document.getElementById("' . SearchbarIds::INPUT . '");
                const row = input && input.parentNode;
                if (!pills || !row || pills.parentNode !== row || (own ? row.lastElementChild === pills : pills.nextElementSibling === input)) return;
                const focused = pills.contains(document.activeElement) ? document.activeElement : null;
                own ? row.appendChild(pills) : row.insertBefore(pills, input);
                if (focused && document.activeElement !== focused) focused.focus({preventScroll: true});
            };
            window.searchbarPlacePills = () => {
                const pills = document.getElementById("' . SearchbarIds::PILLS . '");
                // Nothing to show (no entity, no rule): no empty strip.
                const own = !!pills && !!window.navbar_search_opened && isStacked() && !!pills.querySelector(".search-rule-pills");
                // Moved before the editor is scrolled to (below): a moved row starts back at its start.
                placePillsInDom(pills, own);
                if (pills) PILLS_ROW.forEach((c) => pills.classList.toggle(c, own));
                // A pill editor is scrolled to: the pills\' refresh brings a new row, at its start, and the edited pill
                // may have been far along it (its field\'s focus came before the row, it didn\'t scroll it).
                const editor = own ? pills.querySelector(".inline-filter-editor") : null;
                if (editor) {
                    const box = editor.getBoundingClientRect();
                    const view = pills.getBoundingClientRect();
                    if (box.left < view.left || box.right > view.right) pills.scrollLeft += box.left - view.left - 8;
                }
                const measure = () => { window.searchbarPillsRow = own ? Math.ceil(pills.getBoundingClientRect().height) : 0; };
                measure();
                $("#search-panel-container").css(stackedBox());

                window.searchbarPillsResize && window.searchbarPillsResize.disconnect();
                window.searchbarPillsResize = null;
                if (own && window.ResizeObserver) {
                    window.searchbarPillsResize = new ResizeObserver(() => {
                        // A refresh replaced the pills (their load places the new ones).
                        if (!pills.isConnected) return;
                        const row = window.searchbarPillsRow;
                        measure();
                        if (window.searchbarPillsRow !== row) $("#search-panel-container").css(stackedBox());
                    });
                    window.searchbarPillsResize.observe(pills);
                }
            }

            window.openSearchPanel = () => {
                const searchPanel = $("#search-panel-container");
                searchPanel.fadeIn(250);
                window.navbar_search_opened = true;
                searchbarSetCompact();
                searchbarAriaExpanded();

                // The results column loads when the panel is first shown, not on every page view.
                window.loadSearchResults && window.loadSearchResults();

                $(".search-close-btn").show();

                searchbarEmitPanel();
            }

            window.closeSearchPanel = (fast = false) => {
                const searchPanel = $("#search-panel-container");
                // Closed, the results are unmounted (SearchPanel\'s searchbarParkResults), once out of sight: mounted,
                // every pill change landing in the closed navbar refreshed them too (their query), and the pills waited
                // for it.
                const park = () => { if (!window.navbar_search_opened && window.searchbarParkResults) searchbarParkResults(); };
                // With the pills on their own row (below md) the panel goes at once, as the row does (it folds into the
                // chip, in the navbar\'s row): fading, the panel jumped up by the row\'s height as its top was reset.
                const instant = fast || !!window.searchbarPillsRow;

                if (instant) searchPanel.stop(true, true).hide();
                else searchPanel.fadeOut(250, park);

                window.navbar_search_opened = false;
                searchbarSetCompact();
                searchbarAriaExpanded();
                window.searchbarKbReset && searchbarKbReset();
                if (instant) park();
                window.searchbarPanelScroll = null;

                $(".search-close-btn").hide();

                searchbarEmitPanel();
            }

            // No native submit of the navbar\'s forms: Enter in the search input submitted its own <form>
            // (#navbar-search-box, nested in the navbar\'s), the page reloaded as ?search=… and the search was never
            // sent. In Chrome that submit reached neither the navbar\'s form nor the document in the bubble phase:
            // the guard is on the document in the capture phase, once per page (a tab opened before an update gets it
            // on its next navbar load), for every form in the navbar (the pill editors too).
            if (!window.searchbarSubmitGuard) {
                window.searchbarSubmitGuard = true;
                document.addEventListener("submit", (e) => {
                    if (e.target && e.target.closest && e.target.closest("#navbar-search")) e.preventDefault();
                }, true);
            }

            // Registered once per page, apart from the listeners below: a tab opened before an update gets them on
            // its next navbar load. They call the window.* helpers, redefined by each load.
            if (!window.searchbarMediaListeners && window.matchMedia) {
                window.searchbarMediaListeners = true;

                const onCross = (px, fn) => {
                    const query = window.matchMedia("(min-width: " + px + "px)");
                    query.addEventListener ? query.addEventListener("change", fn) : query.addListener(fn);
                };
                onCross(768, () => {
                    window.searchbarPlacePills && searchbarPlacePills();
                    window.setSearchFiltersVisible && setSearchFiltersVisible(window.searchFiltersVisible !== false);
                });
                onCross(1024, () => window.searchbarEmitPanel && searchbarEmitPanel());
            }

            if (opened) {
                openSearchPanel();
            } else {
                closeSearchPanel(true);
            }

            // A favorite opened with the keyboard reloaded the navbar: the focus goes back to the new input.
            window.searchbarKbAfterRefresh && searchbarKbAfterRefresh();

            // The container (a navbar load makes a new one) and the results column (a panel load makes a new one)
            // are watched: the user\'s scrolls are saved, and the scroll is put back now and each time the results
            // column resizes: it reloads (skeleton, then results) and changes with the typed text (the filters stay
            // where they were).
            window.searchbarWatchPanelScroll = () => {
                const container = document.getElementById("search-panel-container");
                if (container && !container.dataset.searchbarScroll) {
                    container.dataset.searchbarScroll = "1";
                    container.addEventListener("scroll", () => {
                        // A replaced container still scrolling (a refresh landed mid-scroll) says nothing of the new one.
                        if (container !== document.getElementById("search-panel-container")) return;
                        if (container.scrollTop === window.searchbarPanelScrollSet) return;
                        window.searchbarPanelScrollSet = null;
                        window.searchbarSavePanelScroll && searchbarSavePanelScroll();
                    }, {passive: true});
                }
                searchbarRestorePanelScroll();
                if (window.ResizeObserver) {
                    window.searchbarPanelResize && window.searchbarPanelResize.disconnect();
                    window.searchbarPanelResize = new ResizeObserver(() => window.searchbarRestorePanelScroll && searchbarRestorePanelScroll());
                    const results = document.getElementById("search-results-column");
                    if (results) window.searchbarPanelResize.observe(results);
                }
            }

            // SearchPanel\'s load (an entity picked, Back: a new top bar and new columns in the same container): the
            // tab, the filters column\'s layout and the scroll are applied to the new nodes.
            window.searchbarPanelLoaded = () => {
                switchSearchTab(window.searchActiveTab || "filters");
                setSearchFiltersVisible(window.searchFiltersVisible !== false);
                searchbarWatchPanelScroll();
            }

            searchbarWatchPanelScroll();

            if (!window.searchbarPanelListeners) {
                window.searchbarPanelListeners = true;

                document.addEventListener("click", (event) => {
                    const navbarSearch = document.getElementById("navbar-search");

                    if (!navbarSearch || !window.navbar_search_opened) return;

                    // composedPath: still right when a refresh already replaced the clicked node.
                    const path = event.composedPath ? event.composedPath() : [];
                    const isInside = path.includes(navbarSearch) || navbarSearch.contains(event.target) || event.target.classList?.contains("navbar-search-input");
                    // Modals, and the date picker calendar of a pill editor (appended to <body>).
                    const isOnOverlay = !!event.target.closest?.(".vlMask, .flatpickr-calendar");

                    if (!isInside && !isOnOverlay) {
                        closeSearchPanel();
                    }
                });

                // Not under a modal or drawer: their masks are always in the page (the kompo::app layout mounts
                // <vl-floating-elements>, shown by v-show), a rendered one is open (any mask found kept Esc from closing).
                // Not with a pill editor open either (Esc cancels it). The masks are looked at last: on every page,
                // for Esc only (their client rects lay the page out).
                document.addEventListener("keydown", (event) => {
                    if (event.key !== "Escape" || !window.navbar_search_opened || document.querySelector(".inline-filter-editor")) return;
                    const overlay = [...document.querySelectorAll(".vlMask")].some((n) => n.getClientRects().length > 0);
                    if (!overlay) closeSearchPanel();
                });
            }
        }'));
    }
}
