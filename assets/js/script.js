// WP Post Filter Grid front-end logic:
// filtering + sorting + full-content AJAX search + clear filters
// + back-button persistence (bfcache) + clean URL state (named params)
// + mobile filters toggle (show/hide)
(function () {
    'use strict';

    /**
     * Store server-side search matches per wrapper.
     * WeakMap<HTMLElement, { query: string, ids: Set<string> }>
     */
    const serverSearchState = new WeakMap();

    /**
     * Cache search results per wrapper and query to reduce AJAX calls.
     * WeakMap<HTMLElement, Map<string, Set<string>>>
     */
    const serverSearchCache = new WeakMap();

    /**
     * Debounce timers per wrapper for search input.
     * WeakMap<HTMLElement, number>
     */
    const searchTimers = new WeakMap();

    /**
     * -----------------------------------
     * MOBILE FILTERS TOGGLE HELPERS
     * -----------------------------------
     * Requires:
     * - a button .wp-pfg-filters-toggle in markup
     * - CSS that hides .wp-pfg-filters by default on mobile, shows when wrapper has .is-filters-open
     */
    function setFiltersOpen(wrapper, open) {
        const btn = wrapper.querySelector('.wp-pfg-filters-toggle');
        if (!btn) return;

        wrapper.classList.toggle('is-filters-open', !!open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        btn.textContent = open ? 'Hide Filters' : 'Show Filters';
    }

    function hasActiveState(wrapper) {
        const anyDropdown = Array.from(wrapper.querySelectorAll('.wp-pfg-filter-select'))
            .some(sel => (sel.value || '').trim() !== '');

        const searchInput = wrapper.querySelector('.wp-pfg-search-input');
        const hasSearch = searchInput ? (searchInput.value || '').trim() !== '' : false;

        const sortSelect = wrapper.querySelector('.wp-pfg-sort-select');
        const hasSort = sortSelect ? (sortSelect.value || 'default') !== 'default' : false;

        return anyDropdown || hasSearch || hasSort;
    }

    /**
     * -----------------------------------
     * CLEAN URL STATE (named params)
     * -----------------------------------
     * Dropdowns write: ?{param_key}={term_slug}
     * Search writes:   ?q=...
     * Sort writes:     ?sort=...
     *
     * Example:
     *   ?type=introductory_tutorials&expertise=character_art_tutorials&q=metal&sort=newest
     */
    function applyStateFromURL(wrapper) {
        const params = new URLSearchParams(window.location.search);

        // Restore dropdowns based on each select's data-param-key
        const selects = Array.from(wrapper.querySelectorAll('.wp-pfg-filter-select'));
        selects.forEach(sel => {
            const key = (sel.getAttribute('data-param-key') || '').trim();
            if (!key) return;

            const slug = (params.get(key) || '').trim();

            if (!slug) {
                sel.value = '';
                return;
            }

            // Find option with matching data-slug; set select value to that option's token
            const match = Array.from(sel.options).find(opt => (opt.getAttribute('data-slug') || '').trim() === slug);
            sel.value = match ? match.value : '';
        });

        // Restore search + sort
        const searchInput = wrapper.querySelector('.wp-pfg-search-input');
        if (searchInput) searchInput.value = (params.get('q') || '').trim();

        const sortSelect = wrapper.querySelector('.wp-pfg-sort-select');
        if (sortSelect) sortSelect.value = (params.get('sort') || 'default').trim() || 'default';
    }

    function writeStateToURL(wrapper) {
        const params = new URLSearchParams(window.location.search);

        // Remove any existing dropdown params for this wrapper (prevents stale params when clearing/changing)
        const selects = Array.from(wrapper.querySelectorAll('.wp-pfg-filter-select'));
        selects.forEach(sel => {
            const key = (sel.getAttribute('data-param-key') || '').trim();
            if (key) params.delete(key);
        });

        // Dropdowns: write ONLY non-empty selections as ?{key}={slug}
        selects.forEach(sel => {
            const key = (sel.getAttribute('data-param-key') || '').trim();
            if (!key) return;

            const selectedOpt = sel.options[sel.selectedIndex];
            const slug = selectedOpt ? (selectedOpt.getAttribute('data-slug') || '').trim() : '';

            if (slug) params.set(key, slug);
        });

        // Search + Sort
        const searchInput = wrapper.querySelector('.wp-pfg-search-input');
        const q = searchInput ? (searchInput.value || '').trim() : '';
        if (q) params.set('q', q);
        else params.delete('q');

        const sortSelect = wrapper.querySelector('.wp-pfg-sort-select');
        const sortVal = sortSelect ? (sortSelect.value || 'default').trim() : 'default';
        if (sortVal && sortVal !== 'default') params.set('sort', sortVal);
        else params.delete('sort');

        // If nothing is active, keep URL clean (no trailing ?)
        const qs = params.toString();
        const newUrl = `${window.location.pathname}${qs ? '?' + qs : ''}${window.location.hash || ''}`;
        window.history.replaceState({}, '', newUrl);
    }

    /**
     * -----------------------------------
     * CORE PIPELINE: FILTER / SEARCH / SORT
     * -----------------------------------
     */
    function applyFilters(wrapper) {
        const dropdowns = wrapper.querySelectorAll('.wp-pfg-filter-select');
        const cards = wrapper.querySelectorAll('.wp-pfg-card');
        const activeFilters = [];

        dropdowns.forEach(select => {
            const val = (select.value || '').trim();
            if (val) activeFilters.push(val);
        });

        // If no filters selected, unhide all (filter-wise)
        if (activeFilters.length === 0) {
            cards.forEach(card => card.classList.remove('is-hidden'));
            return;
        }

        cards.forEach(card => {
            const termsAttr = (card.getAttribute('data-terms') || '').trim();
            if (!termsAttr) {
                card.classList.add('is-hidden');
                return;
            }

            const cardTerms = termsAttr.split(/\s+/);
            const matchesAll = activeFilters.every(token => cardTerms.indexOf(token) !== -1);

            if (matchesAll) card.classList.remove('is-hidden');
            else card.classList.add('is-hidden');
        });
    }

    function applySearch(wrapper) {
        const input = wrapper.querySelector('.wp-pfg-search-input');
        const cards = wrapper.querySelectorAll('.wp-pfg-card');
        if (!input) return;

        const query = (input.value || '').trim();

        // No query means no server constraint
        if (!query) {
            cards.forEach(card => card.classList.remove('search-hidden'));
            serverSearchState.delete(wrapper);
            return;
        }

        const state = serverSearchState.get(wrapper);

        // If we don't have results for this query yet, don't change anything
        if (!state || state.query !== query || !(state.ids instanceof Set)) {
            return;
        }

        cards.forEach(card => {
            const postId = (card.getAttribute('data-post-id') || '').trim();
            if (!postId) {
                card.classList.add('search-hidden');
                return;
            }

            if (state.ids.has(postId)) card.classList.remove('search-hidden');
            else card.classList.add('search-hidden');
        });
    }

    function sortCards(wrapper) {
        const sortSelect = wrapper.querySelector('.wp-pfg-sort-select');
        const grid = wrapper.querySelector('.wp-pfg-grid');
        if (!sortSelect || !grid) return;

        const sortVal = (sortSelect.value || 'default').trim();

        // Keep "no results" element at top of grid
        const noResults = grid.querySelector('.wp-pfg-no-results');
        const cards = Array.from(grid.querySelectorAll('.wp-pfg-card'));

        const compare = (a, b) => {
            if (sortVal === 'newest') {
                return (parseInt(b.getAttribute('data-date') || '0', 10) || 0) - (parseInt(a.getAttribute('data-date') || '0', 10) || 0);
            }
            if (sortVal === 'oldest') {
                return (parseInt(a.getAttribute('data-date') || '0', 10) || 0) - (parseInt(b.getAttribute('data-date') || '0', 10) || 0);
            }
            if (sortVal === 'title-asc') {
                return (a.getAttribute('data-title') || '').localeCompare(b.getAttribute('data-title') || '');
            }
            if (sortVal === 'title-desc') {
                return (b.getAttribute('data-title') || '').localeCompare(a.getAttribute('data-title') || '');
            }
            // default: original index
            return (parseInt(a.getAttribute('data-index') || '0', 10) || 0) - (parseInt(b.getAttribute('data-index') || '0', 10) || 0);
        };

        cards.sort(compare);

        if (noResults) grid.appendChild(noResults);
        cards.forEach(card => grid.appendChild(card));
    }

    function updateNoResults(wrapper) {
        const grid = wrapper.querySelector('.wp-pfg-grid');
        if (!grid) return;

        const noResults = grid.querySelector('.wp-pfg-no-results');
        if (!noResults) return;

        const cards = Array.from(grid.querySelectorAll('.wp-pfg-card'));

        const visibleCount = cards.reduce((count, card) => {
            const hiddenByFilters = card.classList.contains('is-hidden');
            const hiddenBySearch = card.classList.contains('search-hidden');
            return (!hiddenByFilters && !hiddenBySearch) ? count + 1 : count;
        }, 0);

        noResults.style.display = visibleCount === 0 ? 'block' : 'none';
    }

    function runPipeline(wrapper, options) {
        const opts = options || { sort: true, filters: true, search: true };

        if (opts.sort) sortCards(wrapper);
        if (opts.filters) applyFilters(wrapper);
        if (opts.search) applySearch(wrapper);

        updateNoResults(wrapper);
    }

    /**
     * -----------------------------------
     * AJAX FULL CONTENT SEARCH (DEBOUNCED)
     * -----------------------------------
     */
    function scheduleServerSearch(wrapper) {
        const input = wrapper.querySelector('.wp-pfg-search-input');
        if (!input) return;

        const query = (input.value || '').trim();

        // Clear pending timer
        const existingTimer = searchTimers.get(wrapper);
        if (existingTimer) window.clearTimeout(existingTimer);

        const timerId = window.setTimeout(() => {
            // Empty query => clear server constraint and re-run search stage
            if (!query) {
                serverSearchState.delete(wrapper);
                runPipeline(wrapper, { sort: false, filters: false, search: true });
                return;
            }

            // If WP_PFG isn't present, fall back to lightweight client-side search (title+excerpt)
            if (typeof window.WP_PFG === 'undefined' || !WP_PFG.ajaxUrl || !WP_PFG.nonce) {
                const cards = wrapper.querySelectorAll('.wp-pfg-card');
                const qLower = query.toLowerCase();
                cards.forEach(card => {
                    const hay = (card.getAttribute('data-search') || '').toLowerCase();
                    if (!qLower || hay.indexOf(qLower) !== -1) card.classList.remove('search-hidden');
                    else card.classList.add('search-hidden');
                });
                updateNoResults(wrapper);
                return;
            }

            // Cache map per wrapper
            let cacheMap = serverSearchCache.get(wrapper);
            if (!cacheMap) {
                cacheMap = new Map();
                serverSearchCache.set(wrapper, cacheMap);
            }

            // Cache hit
            if (cacheMap.has(query)) {
                serverSearchState.set(wrapper, { query, ids: cacheMap.get(query) });
                runPipeline(wrapper, { sort: false, filters: false, search: true });
                return;
            }

            // Build request
            const formData = new FormData();
formData.append('action', 'wp_pfg_fulltext_search');
formData.append('q', query);

// Only send nonce if it exists (logged-in users)
if (window.WP_PFG && WP_PFG.nonce) {
    formData.append('nonce', WP_PFG.nonce);
}

// Optional category constraint
const includeCatsStr = (wrapper.getAttribute('data-include-cats') || '').trim();
if (includeCatsStr) {
    formData.append('include_cats', includeCatsStr);
}

            fetch(WP_PFG.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
                .then(r => r.json())
                .then(data => {
                    if (!data || !data.success || !data.data || !Array.isArray(data.data.ids)) {
                        serverSearchState.delete(wrapper);
                        runPipeline(wrapper, { sort: false, filters: false, search: true });
                        return;
                    }

                    const idsSet = new Set(data.data.ids.map(String));
                    cacheMap.set(query, idsSet);
                    serverSearchState.set(wrapper, { query, ids: idsSet });

                    runPipeline(wrapper, { sort: false, filters: false, search: true });
                })
                .catch(() => {
                    serverSearchState.delete(wrapper);
                    runPipeline(wrapper, { sort: false, filters: false, search: true });
                });

        }, 250);

        searchTimers.set(wrapper, timerId);
    }

    /**
     * -----------------------------------
     * EVENTS
     * -----------------------------------
     */

    document.addEventListener('change', function (e) {
        const target = e.target;
        if (!(target instanceof HTMLElement)) return;

        // Dropdown filter changes
        if (target.classList.contains('wp-pfg-filter-select')) {
            const wrapper = target.closest('.wp-pfg-wrapper');
            if (!wrapper) return;

            runPipeline(wrapper, { sort: false, filters: true, search: true });
            writeStateToURL(wrapper);

            // If user is interacting with filters on mobile, ensure panel stays open
            setFiltersOpen(wrapper, true);
            return;
        }

        // Sort changes
        if (target.classList.contains('wp-pfg-sort-select')) {
            const wrapper = target.closest('.wp-pfg-wrapper');
            if (!wrapper) return;

            runPipeline(wrapper, { sort: true, filters: true, search: true });
            writeStateToURL(wrapper);

            // If user changes sort on mobile, keep open
            setFiltersOpen(wrapper, true);
            return;
        }
    });

    document.addEventListener('input', function (e) {
        const target = e.target;
        if (!(target instanceof HTMLElement)) return;

        if (target.classList.contains('wp-pfg-search-input')) {
            const wrapper = target.closest('.wp-pfg-wrapper');
            if (!wrapper) return;

            scheduleServerSearch(wrapper);
            writeStateToURL(wrapper);

            // If user is typing search on mobile, keep open
            setFiltersOpen(wrapper, true);
        }
    });

    document.addEventListener('click', function (e) {
        const target = e.target;
        if (!(target instanceof HTMLElement)) return;

        // Clear Filters button
        if (target.classList.contains('wp-pfg-clear-filters')) {
            const wrapper = target.closest('.wp-pfg-wrapper');
            if (!wrapper) return;

            wrapper.querySelectorAll('.wp-pfg-filter-select').forEach(sel => {
                sel.value = '';
            });

            const sortSelect = wrapper.querySelector('.wp-pfg-sort-select');
            if (sortSelect) sortSelect.value = 'default';

            const searchInput = wrapper.querySelector('.wp-pfg-search-input');
            if (searchInput) searchInput.value = '';

            serverSearchState.delete(wrapper);

            runPipeline(wrapper, { sort: true, filters: true, search: true });
            writeStateToURL(wrapper);

            // Nothing active now → close on mobile
            setFiltersOpen(wrapper, false);
            return;
        }

        // Mobile filters toggle button
        if (target.classList.contains('wp-pfg-filters-toggle')) {
            const wrapper = target.closest('.wp-pfg-wrapper');
            if (!wrapper) return;

            const isOpen = wrapper.classList.contains('is-filters-open');
            setFiltersOpen(wrapper, !isOpen);
        }
    });

    /**
     * -----------------------------------
     * INIT: restore from URL, run pipeline
     * -----------------------------------
     */
    document.addEventListener('DOMContentLoaded', function () {
        const wrappers = document.querySelectorAll('.wp-pfg-wrapper');
        wrappers.forEach(wrapper => {
            // Restore UI from URL (if present)
            applyStateFromURL(wrapper);

            // Set initial mobile open/closed state based on whether anything is active
            setFiltersOpen(wrapper, hasActiveState(wrapper));

            // If search exists, trigger server search (async)
            const searchInput = wrapper.querySelector('.wp-pfg-search-input');
            const q = searchInput ? (searchInput.value || '').trim() : '';
            if (q) {
                scheduleServerSearch(wrapper);
            }

            runPipeline(wrapper, { sort: true, filters: true, search: true });

            // Normalize URL on load
            writeStateToURL(wrapper);
        });
    });

    /**
     * -----------------------------------
     * BACK/FORWARD (bfcache) FIX
     * -----------------------------------
     */
    window.addEventListener('pageshow', function () {
        const wrappers = document.querySelectorAll('.wp-pfg-wrapper');
        wrappers.forEach(wrapper => {
            // Re-apply mobile open/closed based on current restored UI state
            setFiltersOpen(wrapper, hasActiveState(wrapper));

            const searchInput = wrapper.querySelector('.wp-pfg-search-input');
            const q = searchInput ? (searchInput.value || '').trim() : '';

            if (q) {
                scheduleServerSearch(wrapper);
            } else {
                serverSearchState.delete(wrapper);
            }

            runPipeline(wrapper, { sort: true, filters: true, search: true });
            writeStateToURL(wrapper);
        });
    });

})();
