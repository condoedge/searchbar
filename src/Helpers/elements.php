<?php

function _RulePill($key, $valueEl = null, $icon = null)
{
    return _Flex(
        _Html($key)->when($icon, fn($el) => $el->icon($icon))->class('text-sm bg-level1 text-white px-2 p-1 rounded-l-md whitespace-nowrap'),

        !$valueEl ? null : $valueEl->class('text-sm bg-level4 px-2 p-1 rounded-r-md items-center min-w-0'),
    )->class('rounded-md w-max overflow-hidden items-stretch shrink-0');
}

/**
 * A pill being edited: label + editor. Not overflow-hidden, so neither the focus ring nor a select's dropdown is
 * clipped. $enterApplies: false where Enter already means something in the input (selects, date pickers).
 */
function _RuleInlinePill($key, $editorEl, $enterApplies = true)
{
    return _Flex(
        _Html($key)->class('text-sm bg-level1 text-white px-2 p-1 rounded-l-md whitespace-nowrap self-stretch flex items-center'),
        $editorEl->class('bg-white px-2 py-0.5 rounded-r-md self-stretch'),
    )->class('inline-filter-editor rounded-md w-max items-center ring-2 ring-level3 shadow-sm shrink-0')
        ->class($enterApplies ? '' : 'inline-filter-keep-enter');
}

/**
 * Browser helpers of the searchbar, registered once per page by the komponents that need them (NavbarSearch,
 * SearchResults) through `_Hidden()->onLoad(fn($e) => $e->run(searchbarClientJs()))`.
 *
 * - searchbarBusy(): locks the pills and panel while a state change runs. The komponents that change refresh
 *   (SearchService::refreshTargets()) and their loads unlock; a failsafe unlocks if the request fails. A second click
 *   before the refresh (double click) sent the request twice.
 * - searchbarResultsLoaded(): results that were still lazy-loading during a change reload once when they land.
 * - searchbarLoadingOn/Off(): the same while typed text is being searched. Off only unlocks once the rendered
 *   results are for the text in the input (an older response can land while newer text is still debouncing), so a
 *   "Search by" chip never applies a stale search. searchbarRelockTyping(): the pills', filters' and entity list's
 *   loads lock their new nodes again while that text is on its way.
 * - searchbarQueueSearch/SendSearch(): the navbar's typed text is sent from one place, the hidden
 *   #navbar-search-send link: typing is debounced here, Enter sends at once and cancels the pending send. Enter on
 *   text already sent sends nothing, unless that send still has no results after 3 s (it failed: retry).
 * - Pill editors (.inline-filter-editor): Enter / Tab or click away press ✓, Esc presses ✕; ✓ / ✕ run once.
 * - Column header funnels (HasSearchbarColumnHeaders::searchbarTh()): a click opens the field's pill through the
 *   table's hidden .searchbar-th-link, not the header's own sort menu.
 * - Keyboard in the navbar panel (a combobox: the focus stays in the input): arrows move an outline over its
 *   .searchbar-nav-item items, Enter clicks the outlined one instead of searching (a modal it opens gets the focus).
 *   searchbarKbReset() clears it, searchbarKbAfterRefresh() (the navbar komponents' loads) puts back the focus and
 *   the outline a refresh took.
 * - Recent searches: a search used (Enter, a result opened from the panel) is remembered (searchbarRememberSearch(),
 *   a searchstate/remember beacon, once that text's own send is in); typing hides the list of recent searches
 *   (searchbarHideRecents(): it is for an empty input).
 */
function searchbarClientJs(): string
{
    return <<<'JS'
() => {
    if (window.searchbarClientReady) return;
    window.searchbarClientReady = true;

    const FAILSAFE_MS = 12000;
    const lock = (nodes, dim = true) => nodes.forEach((n) => { n.classList.add('pointer-events-none'); dim && n.classList.add('opacity-60'); });
    const unlock = (nodes) => nodes.forEach((n) => n.classList.remove('pointer-events-none', 'opacity-60'));
    const all = (selector) => [...document.querySelectorAll(selector)];

    // The searchbar an element belongs to: the navbar, or a table's .searchbar-scope bar. Dropdown menus are
    // teleported to <body> (condoedge Dropdown override), so their links name their bar (searchbar-for-{scope class}).
    const scopeOf = (el) => {
        if (!el || !el.closest) return null;
        const inside = el.closest('#navbar-search') || el.closest('.searchbar-scope');
        if (inside) return inside;
        const linked = el.closest('[class*="searchbar-for-"]');
        const ref = linked && [...linked.classList].find((c) => c.startsWith('searchbar-for-'));
        return ref ? document.querySelector('.searchbar-scope.' + ref.slice('searchbar-for-'.length)) : null;
    };

    // Only the searchbar that was clicked is locked (the navbar, or a table's .searchbar-scope bar), and every
    // searchbar komponent unlocks when it loads: nodes the refresh doesn't replace stayed dead until the failsafe.
    let locked = [];
    document.addEventListener('click', (e) => { window.searchbarLastClick = e.target; }, true);

    window.searchbarUnlock = () => {
        clearTimeout(window.searchbarBusyTimer);
        unlock(locked);
        locked = [];
    };

    // The navbar's results column, once lazy-loaded (EnhancedSearchbar).
    const resultsMounted = () => !!document.querySelector('#navbar-search .searchbar-results');

    window.searchbarBusy = () => {
        window.searchbarUnlock();
        const root = scopeOf(window.searchbarLastClick);
        if (!root) return;
        // A navbar change while its results are still lazy-loading can't refresh them (not mounted yet: Kompo skips
        // them): the results that land were computed before the change, so they reload once (searchbarResultsLoaded).
        if (root.id === 'navbar-search' && window.searchResultsRequested && !resultsMounted()) window.searchbarResultsDirty = true;
        // A table's "Filter" menu is teleported out of its bar: its items (option chips, fields) name the bar and are
        // locked with it (the menu stays open under the mouse: a double click toggled a chip back).
        const scope = root.id === 'navbar-search' ? null : [...root.classList].find((c) => c.startsWith('searchbar-scope-'));
        const dimmed = root.id === 'navbar-search'
            ? [...root.querySelectorAll('.search-rule-pills, #search-content-filters')]
            : [root, ...(scope ? all('.searchbar-for-' + scope).filter((n) => !root.contains(n)) : [])];
        const blocked = root.id === 'navbar-search' ? [...root.querySelectorAll('#search-panel-container')] : [];
        lock(dimmed);
        lock(blocked, false);
        locked = [...dimmed, ...blocked];
        window.searchbarBusyTimer = setTimeout(window.searchbarUnlock, FAILSAFE_MS);
    };

    // The results column's load (EnhancedSearchbar): results owed a reload (see searchbarBusy) reload through its
    // hidden refresh link. SearchPanel's load clears the debt: its results are requested after the change. Results
    // landing in a panel closed meanwhile are unmounted (SearchPanel's searchbarParkResults): the next opening
    // loads them again.
    window.searchbarResultsLoaded = () => {
        if (!window.navbar_search_opened && window.searchbarParkResults && window.searchbarParkResults()) return;
        if (!window.searchbarResultsDirty) return;
        window.searchbarResultsDirty = false;
        document.querySelector('#navbar-search .searchbar-results-reload')?.click();
    };

    const countPills = (panel, loading) => panel.querySelectorAll('.entityCountPill').forEach((pill) => {
        const empty = loading || ['0', '?'].includes(pill.textContent.trim());
        pill.classList.toggle('hidden', loading);
        pill.parentElement.classList.toggle('bg-graylight', empty);
        pill.parentElement.classList.toggle('text-graydark', empty);
        pill.parentElement.classList.toggle('bg-greenlight', !empty);
        pill.parentElement.classList.toggle('text-greendark', !empty);
    });

    // While typed text is on its way (searchbarLoadingOn until searchbarLoadingOff), the panel's links and the pills
    // are locked: a "Search by" chip would apply the older text. So are the entity rows (divs: no disabled link): one
    // picked then built its default rule from the older text, and the refreshed input lost the typed one.
    const entityRows = (panel) => [...panel.querySelectorAll('#searchable-options .searchbar-nav-item')];
    const typingLock = (root) => {
        const panel = root.querySelector('#search-panel-container');
        if (panel) {
            panel.querySelectorAll('a').forEach((a) => a.setAttribute('disabled', 'disabled'));
            lock(entityRows(panel), false);
            panel.querySelectorAll('.searchbar-loading').forEach((n) => n.classList.remove('hidden'));
            countPills(panel, true);
        }
        lock([...root.querySelectorAll('.search-rule-pills')]);
    };
    let typingSpinner = null;

    // RECENT SEARCHES. A search used (Enter in the navbar, a result opened from its panel) becomes one of the user's
    // recent searches: searchstate/remember, at the URL the send link carries while they are on (none for a guest).
    // Its own request: Enter on text already sent posts no search (searchbarSendSearch). Never at the same time as
    // that text's send: both requests write the session, and the older one's write would win (the stored text went
    // back). Posted once the send is in (searchbarLoadingOff), with the text used. A beacon: a result card leaves the
    // page at once.
    let rememberAfterSend = null;
    const postRemember = (search) => {
        const url = document.getElementById('navbar-search-send')?.getAttribute('data-searchbar-remember');
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!url || !token) return;
        const body = new FormData();
        body.append('_token', token);
        if (typeof search === 'string') body.append('search', search);
        if (navigator.sendBeacon && navigator.sendBeacon(url, body)) return;
        if (window.fetch) window.fetch(url, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true }).catch(() => {});
    };
    window.searchbarRememberSearch = (search) => {
        const spinner = typingSpinner && document.getElementById(typingSpinner);
        if (spinner && !spinner.classList.contains('hidden')) {
            rememberAfterSend = { search: search };
            return;
        }
        postRemember(search);
    };
    const rememberPending = () => {
        const pending = rememberAfterSend;
        rememberAfterSend = null;
        if (pending) postRemember(pending.search);
    };

    window.searchbarLoadingOn = (spinnerId) => {
        const root = document.getElementById('navbar-search');
        if (!root) return;
        typingSpinner = spinnerId;
        document.getElementById(spinnerId)?.classList.remove('hidden');
        typingLock(root);
        clearTimeout(window.searchbarLoadingTimer);
        window.searchbarLoadingTimer = setTimeout(() => {
            // No results came back: the text may not be stored, so Enter can send it again.
            window.searchbarSentSearch = null;
            window.searchbarLoadingOff(spinnerId);
        }, FAILSAFE_MS);
    };

    // The pills' and the filters column's loads: a chip clicked, then typing before its refresh landed, and the
    // refresh brought new nodes, unlocked while the text was still on its way (the input isn't refreshed with them).
    // Locked again while the navbar's spinner shows; the failsafe already runs.
    window.searchbarRelockTyping = () => {
        const root = document.getElementById('navbar-search');
        const spinner = typingSpinner && document.getElementById(typingSpinner);
        if (root && spinner && !spinner.classList.contains('hidden')) typingLock(root);
    };

    const searchInput = () => document.querySelector('#navbar-search .navbar-search-input input');
    // Trimmed like the server's TrimStrings (also U+FEFF and U+200B, which String.trim() keeps).
    const norm = (s) => String(s ?? '').replace(/^[\s﻿​]+|[\s﻿​]+$/g, '');

    // A table's "Filter" fields turn the typed text into a filter: disabled while its search box is empty, and a
    // click on one is dropped then. Only when the box is found empty: otherwise the server decides (it ignores an
    // empty text too); a box looked up by DOM ancestry was never found from the teleported menu, blocking every click.
    const tableSearchIsEmpty = (el) => {
        const box = scopeOf(el)?.querySelector('.searchbar-search-box input');
        return !!box && !norm(box.value);
    };
    window.searchbarSyncFilterMenus = () => all('.searchbar-needs-search').forEach((field) => {
        tableSearchIsEmpty(field) ? field.setAttribute('disabled', 'disabled') : field.removeAttribute('disabled');
    });
    document.addEventListener('input', (e) => {
        if (e.target.closest && e.target.closest('.searchbar-search-box')) window.searchbarSyncFilterMenus();
    }, true);
    document.addEventListener('click', (e) => {
        const field = e.target.closest ? e.target.closest('.searchbar-needs-search') : null;
        if (field && tableSearchIsEmpty(field)) { e.preventDefault(); e.stopImmediatePropagation(); }
        // The menu may render its links when opened.
        if (scopeOf(e.target)) setTimeout(window.searchbarSyncFilterMenus, 0);
    }, true);

    window.searchbarLoadingOff = (spinnerId, renderedSearch) => {
        const root = document.getElementById('navbar-search');
        if (!root) return;
        if (typeof renderedSearch === 'string') {
            window.searchbarRenderedSearch = renderedSearch;
            const input = searchInput();
            if (input && norm(input.value) !== norm(renderedSearch)) {
                // Results for newer text are normally on their way. If none come (their refresh found no results
                // column yet), search the input again, once per text, rather than waiting for the failsafe. Kompo
                // inputs are Vue controlled: a dispatched native input event runs their interactions.
                clearTimeout(window.searchbarResyncTimer);
                window.searchbarResyncTimer = setTimeout(() => {
                    const current = searchInput();
                    if (current && norm(current.value) !== norm(window.searchbarRenderedSearch) && window.searchbarResyncedFor !== current.value) {
                        window.searchbarResyncedFor = current.value;
                        current.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }, 2000);
                return;
            }
        }
        clearTimeout(window.searchbarResyncTimer);
        clearTimeout(window.searchbarLoadingTimer);
        document.getElementById(spinnerId)?.classList.add('hidden');
        const panel = root.querySelector('#search-panel-container');
        if (panel) {
            panel.querySelectorAll('a[disabled]').forEach((a) => a.removeAttribute('disabled'));
            entityRows(panel).forEach((n) => n.classList.remove('pointer-events-none'));
            panel.querySelectorAll('.searchbar-loading').forEach((n) => n.classList.add('hidden'));
            countPills(panel, false);
        }
        unlock([...root.querySelectorAll('.search-rule-pills')]);
        // The send is in (or given up by the failsafe, long after its post): a search used meanwhile is remembered.
        rememberPending();
    };

    // The navbar's typed text is searched from one place, its hidden #navbar-search-send link. Kompo debounced the
    // input's own request, and Enter never cancelled it: typing then Enter sent the text twice (two posts, four
    // refreshes). Typing queues the send (the lock goes on at once), Enter sends now and drops the queued one.
    window.searchbarQueueSearch = (spinnerId, delayMs = 600) => {
        window.searchbarLoadingOn(spinnerId);
        clearTimeout(window.searchbarSearchTimer);
        window.searchbarSearchTimer = setTimeout(() => window.searchbarSendSearch(false, spinnerId), delayMs);
    };
    // A send with no results after this long (spinner still on) most likely failed (500, 419, network: no refresh
    // turns the lock off), so Enter may send the same text again instead of waiting for the failsafe. Not detected
    // with the link's onError: an error interaction replaces Kompo's own error handling (alerts, session expiry).
    const RETRY_MS = 3000;
    window.searchbarSendSearch = (fromEnter, spinnerId) => {
        const pending = !!window.searchbarSearchTimer;
        clearTimeout(window.searchbarSearchTimer);
        window.searchbarSearchTimer = null;
        const text = norm(searchInput()?.value);
        const spinner = document.getElementById(spinnerId);
        const unanswered = !!spinner && !spinner.classList.contains('hidden') && Date.now() - (window.searchbarSentAt || 0) > RETRY_MS;
        // Enter on text already sent (the debounce fired, or the navbar rendered it): no identical second request. Enter
        // uses the search either way: remembered (once its send is in).
        if (fromEnter && !pending && !unanswered && text === norm(window.searchbarSentSearch)) {
            window.searchbarRememberSearch(text);
            return;
        }
        window.searchbarSentSearch = text;
        window.searchbarSentAt = Date.now();
        window.searchbarLoadingOn(spinnerId);
        // A programmatic click: the pointer-events-none locks don't stop it.
        document.getElementById('navbar-search-send')?.click();
        if (fromEnter) window.searchbarRememberSearch(text);
    };

    // A result opened from the navbar panel (a click, or Enter on the outlined card) uses the search it was found by:
    // the text its results are for (searchbarLoadingOff). Not one locked by a change running.
    document.addEventListener('click', (e) => {
        const card = e.target.closest ? e.target.closest('#navbar-search .searchbar-result') : null;
        if (!card || card.closest('[disabled], .pointer-events-none')) return;
        window.searchbarRememberSearch(typeof window.searchbarRenderedSearch === 'string' ? window.searchbarRenderedSearch : undefined);
    }, true);

    // The recent searches are for an empty input: typed text hides them at once, before the results' refresh stops
    // rendering them (and a refresh of their own list landing meanwhile, from its load). An emptied input doesn't show
    // them: its results bring them back.
    window.searchbarHideRecents = () => {
        if (norm(searchInput()?.value) !== '') all('#navbar-search .searchbar-recent').forEach((n) => n.classList.add('hidden'));
    };

    // A table's column header funnel (searchbarTh()). A Th label is HTML (v-html): the funnel clicks the hidden Kompo
    // link of the table's bar (.searchbar-th-link, found by the bar's scope class), which posts and refreshes the
    // table. Stopped at the document in the capture phase: the header's own click (its sort menu) never runs. The
    // funnel is focusable (tabindex): Enter / Space open it too, for keyboard users.
    const funnelOf = (n) => (n && n.closest ? n.closest('[data-searchbar-th]') : null);
    const openFunnel = (funnel) => {
        const scope = funnel.dataset.searchbarScope || '';
        const bar = /^searchbar-scope-[\w-]+$/.test(scope) ? document.querySelector('.searchbar-scope.' + scope) : null;
        // The bar is locked while a change runs, and a pill editor being applied (the mousedown that closed it) is
        // one: a programmatic click isn't stopped by pointer-events-none, so it is checked here.
        if (!bar || bar.classList.contains('pointer-events-none') || document.querySelector('.inline-filter-editor[data-busy]')) return;
        const key = funnel.dataset.searchbarTh;
        const link = [...bar.querySelectorAll('.searchbar-th-link')].find((l) => l.dataset.searchbarKey === key || l.classList.contains('searchbar-th-key-' + key));
        if (link) link.click(); // searchbarLastClick becomes the link: searchbarBusy() locks this bar
    };
    document.addEventListener('click', (e) => {
        const funnel = funnelOf(e.target);
        if (!funnel) return;
        e.preventDefault();
        e.stopPropagation();
        openFunnel(funnel);
    }, true);
    document.addEventListener('keydown', (e) => {
        const funnel = e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar' ? funnelOf(e.target) : null;
        if (!funnel) return;
        e.preventDefault(); // Space would scroll the page
        e.stopPropagation();
        if (!e.repeat) openFunnel(funnel);
    }, true);

    const editorOf = (n) => (n && n.closest ? n.closest('.inline-filter-editor') : null);
    const inPicker = (n) => !!(n && n.closest && n.closest('.flatpickr-calendar, .vlOptions'));
    const press = (editor, selector) => {
        const link = editor && !editor.dataset.busy ? editor.querySelector(selector) : null;
        if (link) link.click();
    };

    document.addEventListener('click', (e) => {
        const link = e.target.closest ? e.target.closest('.inline-filter-apply, .inline-filter-cancel') : null;
        const editor = editorOf(link);
        if (!editor) return;
        if (editor.dataset.busy) { e.preventDefault(); e.stopImmediatePropagation(); return; }
        editor.dataset.busy = '1';
        editor.classList.add('opacity-60');
        setTimeout(() => { if (editor.isConnected) { delete editor.dataset.busy; editor.classList.remove('opacity-60'); } }, FAILSAFE_MS);
    }, true);

    document.addEventListener('mousedown', (e) => {
        if (editorOf(e.target)) {
            // Pressing ✓ / ✕ keeps the focus in the editor, so it doesn't also count as leaving it.
            if (e.target.closest('.inline-filter-apply, .inline-filter-cancel')) e.preventDefault();
            return;
        }
        if (inPicker(e.target)) return;
        const editor = all('.inline-filter-editor').find((ed) => !ed.dataset.busy);
        if (!editor) return;
        press(editor, '.inline-filter-apply');
        // Inside the searchbar, the click that closed the editor would start a second state change at the same
        // time (the session keeps the last write): it is swallowed, the refresh shows the applied value.
        if (scopeOf(e.target) || e.target.closest('#search-results') || funnelOf(e.target)) {
            const swallow = (c) => { c.preventDefault(); c.stopPropagation(); };
            document.addEventListener('click', swallow, { capture: true, once: true });
            setTimeout(() => document.removeEventListener('click', swallow, true), 600);
        }
    }, true);

    document.addEventListener('keydown', (e) => {
        // A date editor is never focused, and picking a select option with the mouse drops the focus to <body>:
        // Esc then still cancels the open editor.
        const editor = editorOf(e.target) || (e.key === 'Escape' && (e.target === document.body || !e.target.closest)
            ? all('.inline-filter-editor').find((ed) => !ed.dataset.busy) : null);
        if (!editor) return;
        // A focused ✓ / ✕ activates itself natively.
        if (e.key === 'Enter' && e.target.closest && e.target.closest('.inline-filter-apply, .inline-filter-cancel')) return;
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            press(editor, '.inline-filter-cancel');
        } else if (e.key === 'Enter' && !editor.classList.contains('inline-filter-keep-enter')) {
            e.preventDefault(); // also no native submit of the surrounding <form>
            press(editor, '.inline-filter-apply');
        }
    }, true);

    document.addEventListener('focusout', (e) => {
        const editor = editorOf(e.target);
        if (!editor || !e.relatedTarget || editor.contains(e.relatedTarget) || inPicker(e.relatedTarget)) return;
        press(editor, '.inline-filter-apply');
    });

    // KEYBOARD (the navbar panel, a combobox): the focus stays in the search input and the active item is outlined,
    // so typing goes on working and a refresh can't take a focused item away. ↓ / ↑ move in a column (the results,
    // or the filters / favorites tab shown), → / ← go to the other column once an item is active, Enter clicks it (its
    // own interactions: a page, a modal, a chip's change) instead of searching; a modal or drawer it opens gets the
    // focus (focusOverlay). Items: .searchbar-nav-item. Esc is unchanged (it closes the panel, which clears the
    // outline). Only the navbar input's and chip's keys: the pill editors, the table search boxes and the teleported
    // table menus keep theirs.
    const isNavbarInput = (el) => !!el && el === searchInput();
    // A Kompo modal or drawer is open. Their masks are always in the page (kompo::app's <vl-floating-elements>,
    // shown by v-show): only a rendered one counts (any .vlMask found meant "a modal is open" on every page).
    const overlayOpen = () => all('.vlMask').some((n) => n.getClientRects().length > 0);
    const navbarChip = (el) => (el && el.closest ? el.closest('#navbar-search .searchbar-filters-count') : null);
    const shownChip = () => all('#navbar-search .searchbar-filters-count').find((n) => !n.classList.contains('hidden'));
    const kbRoot = (zone) => {
        const panel = document.querySelector('#navbar-search #search-panel-container');
        if (!panel) return null;
        if (zone === 'results') return panel.querySelector('[id^="search-results-lazy-body-"]');
        // Retracted: 0 wide (side by side) or not displayed (stacked).
        if (window.searchFiltersVisible === false) return null;
        return panel.querySelector(window.searchActiveTab === 'favorites' ? '#search-content-favorites' : '#search-content-filters');
    };
    // Displayed ones only (a hidden row, the other tab's).
    const kbItems = (zone) => {
        const root = kbRoot(zone);
        return root ? [...root.querySelectorAll('.searchbar-nav-item')].filter((n) => n.getClientRects().length > 0) : [];
    };
    let kb = { zone: null, index: -1, el: null };
    let kbSeq = 0;
    // Refreshes re-render the columns: the position is kept, not the node.
    const kbPosition = () => {
        const i = kb.zone ? kbItems(kb.zone).indexOf(kb.el) : -1;
        return i === -1 ? kb.index : i;
    };
    const kbUnmark = () => all('.searchbar-kb-active').forEach((n) => {
        n.classList.remove('searchbar-kb-active');
        n.style.outline = n.style.outlineOffset = '';
    });
    const kbClear = () => {
        kbUnmark();
        searchInput()?.removeAttribute('aria-activedescendant');
        kb = { zone: null, index: -1, el: null };
    };
    // No compiled outline / ring class fits: inline. Around the item (a gap: seen on a selected, green, chip), inside
    // a result card (it fills its scrolling column, which cut an outline around it off).
    const kbSet = (zone, index) => {
        const items = kbItems(zone);
        if (!items.length) return false;
        const i = Math.max(0, Math.min(index, items.length - 1));
        const el = items[i];
        kbUnmark();
        el.classList.add('searchbar-kb-active');
        el.style.outline = '2px solid var(--greenmain, #006241)';
        el.style.outlineOffset = el.classList.contains('searchbar-result') ? '-2px' : '2px';
        if (!el.id) el.id = 'searchbar-kb-item-' + (++kbSeq);
        searchInput()?.setAttribute('aria-activedescendant', el.id);
        if (el.scrollIntoView) el.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        kb = { zone, index: i, el };
        return true;
    };
    // The panel closing (all), a tab switched or the filters column retracted ('filters').
    window.searchbarKbReset = (zone) => { if (!zone || kb.zone === zone) kbClear(); };

    // What had the keyboard focus in the navbar, for a refresh replacing it (searchbarKbAfterRefresh): the input (Enter
    // on a "Search by" chip, an entity or a favorite refreshes it), the chip the closed navbar's pills fold into, a
    // pill. Forgotten once the focus goes elsewhere or the mouse is used; a pill editor's focus changes nothing (it
    // comes and goes with the editor, its refresh brings the focus back).
    let kbFocus = null;
    document.addEventListener('focusin', (e) => {
        if (editorOf(e.target)) return;
        kbFocus = isNavbarInput(e.target) ? 'input' : navbarChip(e.target) ? 'chip'
            : e.target.closest && e.target.closest('#navbar-search .search-rule-pills') ? 'pills' : null;
        if (kbFocus !== 'input') kbClear();
    }, true);

    // An item opening a Kompo modal or drawer (a result card, "Custom filters", "Save current search"): vue-kompo
    // gives it no focus (its Modal only listens to ← / →), so the keys typed next went on to the navbar input under it
    // (a favorite's name became the search it saved). Once one shows (its request answered), the focus goes into it:
    // a field that says so (focusOnLoad) already has it, else its first text field (not a select's search box: its
    // focus opens the options), else the modal itself; only while the focus is still the navbar's (or lost with a
    // refreshed node), so nothing the user focused meanwhile is taken. Watched for a while: a modal's request may be
    // slow (the results column alone took up to 3.8 s).
    const OVERLAY_WAIT_MS = 8000;
    const TEXT_FIELDS = 'input:not([type]), input[type=text], input[type=search], input[type=email], input[type=number], input[type=tel], '
        + 'input[type=url], input[type=password], textarea, [contenteditable=true]';
    let overlayTimer = null;
    const focusOverlay = (since) => {
        clearTimeout(overlayTimer);
        overlayTimer = setTimeout(() => {
            const active = document.activeElement;
            const input = searchInput();
            if (active && active !== document.body && active !== input) return;
            const masks = all('.vlMask').filter((n) => n.getClientRects().length > 0);
            if (!masks.length) {
                if (Date.now() - since < OVERLAY_WAIT_MS) focusOverlay(since);
                return;
            }
            const floats = masks[masks.length - 1].querySelectorAll('.kompoFloat');
            const top = floats[floats.length - 1];
            const field = top && [...top.querySelectorAll(TEXT_FIELDS)].find((n) => !n.disabled && !n.readOnly && n.getClientRects().length > 0
                && !(n.closest('.vlFormField') || n.parentElement).querySelector('.vlOptions'));
            if (field) {
                field.focus();
            } else if (top) {
                if (!top.hasAttribute('tabindex')) top.setAttribute('tabindex', '-1');
                top.focus({ preventScroll: true });
            } else if (input && active === input) {
                input.blur();
            }
        }, 100);
    };
    document.addEventListener('mousedown', () => { kbFocus = null; kbClear(); clearTimeout(overlayTimer); }, true);

    // Only these keys: an open modal (overlayOpen) is looked for on none of the typed ones (its client rects lay the
    // page out, changed by the typing lock).
    const KB_KEYS = ['ArrowDown', 'ArrowUp', 'ArrowRight', 'ArrowLeft', 'Enter'];
    let swallowEnterUp = false;
    document.addEventListener('keydown', (e) => {
        // A held Enter's repeats keep it: its keyup is the first one's (an entity or a favorite lets the outline go
        // at once, and the keyup of a held Enter on it reached the search).
        if (e.key === 'Enter' && !e.repeat) swallowEnterUp = false;
        if (!KB_KEYS.includes(e.key) || e.isComposing || e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) return;

        // The closed navbar's chip stands for the pills: ↓ / → go on to the input (its focus opens the panel).
        if (navbarChip(e.target)) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowRight') { e.preventDefault(); searchInput()?.focus(); }
            return;
        }
        if (!isNavbarInput(e.target) || overlayOpen()) return;

        if (!window.navbar_search_opened) {
            // Esc closed the panel, the input kept the focus: ↓ opens it again; ← at the start of the text goes to
            // the chip (a token field's last token).
            if (e.key === 'ArrowDown' && window.openSearchPanel) {
                e.preventDefault();
                window.openSearchPanel();
            } else if (e.key === 'ArrowLeft' && e.target.selectionStart === 0 && e.target.selectionEnd === 0 && shownChip()) {
                e.preventDefault();
                shownChip().focus();
            }
            return;
        }

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (!(kb.zone && kbSet(kb.zone, kbPosition() + 1))) {
                kbClear();
                kbSet('results', 0) || kbSet('filters', 0);
            }
        } else if (e.key === 'ArrowUp' && kb.zone) {
            e.preventDefault();
            kbPosition() <= 0 ? kbClear() : kbSet(kb.zone, kbPosition() - 1);
        } else if ((e.key === 'ArrowRight' || e.key === 'ArrowLeft') && kb.zone) {
            e.preventDefault();
            const zone = e.key === 'ArrowRight' ? 'filters' : 'results';
            if (zone !== kb.zone) kbSet(zone, kbPosition());
        } else if (e.key === 'Enter' && kb.zone) {
            const items = kbItems(kb.zone);
            // A refreshed filters column has the same chips at the same places (outlined again once
            // searchbarKbAfterRefresh's timer ran); refreshed results are other results: the card now at that place
            // was never outlined, it isn't opened. Nothing to open: the outline goes, Enter searches.
            const el = items.includes(kb.el) ? kb.el : kb.zone === 'filters' ? items[Math.min(kbPosition(), items.length - 1)] : null;
            if (!el) { kbClear(); return; }
            e.preventDefault();
            e.stopPropagation();
            swallowEnterUp = true; // Kompo runs the input's onEnter (the search) on keyup
            // Held down, or a change / a typed search is running (the locks: a programmatic click ignores them; the
            // entity rows are locked with the links while typed text is on its way).
            if (e.repeat || el.closest('[disabled], .pointer-events-none')) return;
            kbFocus = 'input';
            // An entity, a favorite or a recent search replaces the column it is in (the panel, the navbar): the focus
            // comes back, not the outline. A chip's change keeps its column: the chip is outlined again
            // (searchbarKbAfterRefresh).
            if (el.closest('#searchable-options, #search-content-favorites, .searchbar-recent')) kbClear();
            el.click();
            focusOverlay(Date.now());
        }
    }, true);

    // At the document in the capture phase: the input's own keyup (Kompo's onEnter, the search) never runs.
    document.addEventListener('keyup', (e) => {
        if (e.key !== 'Enter' || !swallowEnterUp) return;
        swallowEnterUp = false;
        e.preventDefault();
        e.stopPropagation();
    }, true);

    // Typed text: other results are coming (the recent searches go at once).
    document.addEventListener('input', (e) => {
        if (!isNavbarInput(e.target)) return;
        kbClear();
        window.searchbarHideRecents();
    }, true);

    // Called by the loads of the navbar and its komponents (input, pills, filters column, entity list, results). Once
    // they all landed (after SearchPanel's own 100 ms re-layout): the focus a refresh took away comes back (the input
    // while the panel is open, else the chip), and the active item is outlined again at its place in the new filters
    // column (new results are other results: no outline). Nothing while a pill editor is open (it has the focus; its
    // own refresh comes back here) or a modal is.
    let kbTimer = null;
    window.searchbarKbAfterRefresh = () => {
        clearTimeout(kbTimer);
        kbTimer = setTimeout(() => {
            if (kb.zone === 'results' && kb.el && !kb.el.isConnected) kbClear();
            if (overlayOpen() || document.querySelector('#navbar-search .inline-filter-editor')) return;

            const active = document.activeElement;
            const lost = !active || active === document.body || !active.isConnected;
            const input = searchInput();
            if (lost && (kbFocus === 'chip' || kbFocus === 'pills') && shownChip()) {
                shownChip().focus();
            } else if (lost && kbFocus && input && window.navbar_search_opened) {
                input.focus({ preventScroll: true });
                try { input.setSelectionRange(input.value.length, input.value.length); } catch (err) {}
            }
            if (kb.zone === 'filters' && kb.el && !kb.el.isConnected && input && document.activeElement === input) kbSet('filters', kb.index);
        }, 150);
    };
}
JS;
}
