// WP Post Filter Grid front-end logic: search + sorting + filtering
(function () {
    'use strict';

    /**
     * Apply taxonomy dropdown filters within a single wrapper.
     */
    function applyFilters(wrapper) {
        const dropdowns = wrapper.querySelectorAll('.wp-pfg-filter-select');
        const cards = wrapper.querySelectorAll('.wp-pfg-card');
        const activeFilters = [];

        dropdowns.forEach(select => {
            const val = (select.value || '').trim();
            if (val) {
                activeFilters.push(val);
            }
        });

        if (activeFilters.length === 0) {
            cards.forEach(card => card.classList.remove('is-hidden'));
        } else {
            cards.forEach(card => {
                const termsAttr = card.getAttribute('data-terms') || '';
                const cardTerms = termsAttr.split(/\s+/).filter(Boolean);

                const matches = activeFilters.every(token => cardTerms.indexOf(token) !== -1);

                if (matches) {
                    card.classList.remove('is-hidden');
                } else {
                    card.classList.add('is-hidden');
                }
            });
        }
    }

    /**
     * Apply search filter across cards within a wrapper.
     */
    function applySearch(wrapper) {
        const input = wrapper.querySelector('.wp-pfg-search-input');
        const cards = wrapper.querySelectorAll('.wp-pfg-card');

        if (!input) return;

        const query = (input.value || '').trim().toLowerCase();

        cards.forEach(card => {
            const searchText = (card.getAttribute('data-search') || '').toLowerCase();

            if (query && !searchText.includes(query)) {
                card.classList.add('search-hidden');
            } else {
                card.classList.remove('search-hidden');
            }
        });
    }

    /**
     * Sort cards within a wrapper based on sort dropdown.
     */
    function sortCards(wrapper) {
        const sortSelect = wrapper.querySelector('.wp-pfg-sort-select');
        const grid = wrapper.querySelector('.wp-pfg-grid');
        if (!sortSelect || !grid) return;

        const sortValue = sortSelect.value || 'default';
        const cards = Array.prototype.slice.call(wrapper.querySelectorAll('.wp-pfg-card'));
        if (cards.length <= 1) return;

        const sorted = cards.slice();

        sorted.sort((a, b) => {
            const aDate = parseInt(a.getAttribute('data-date') || '0', 10);
            const bDate = parseInt(b.getAttribute('data-date') || '0', 10);
            const aTitle = (a.getAttribute('data-title') || '').toLowerCase();
            const bTitle = (b.getAttribute('data-title') || '').toLowerCase();
            const aIndex = parseInt(a.getAttribute('data-index') || '0', 10);
            const bIndex = parseInt(b.getAttribute('data-index') || '0', 10);

            switch (sortValue) {
                case 'newest':
                    return bDate - aDate; // latest first
                case 'oldest':
                    return aDate - bDate; // earliest first
                case 'title-asc':
                    return aTitle.localeCompare(bTitle);
                case 'title-desc':
                    return bTitle.localeCompare(aTitle);
                default: // "default"
                    return aIndex - bIndex; // original query order
            }
        });

        sorted.forEach(card => grid.appendChild(card));
    }

    /**
     * Update "No results" visibility based on filters + search.
     */
    function updateNoResults(wrapper) {
        const noResults = wrapper.querySelector('.wp-pfg-no-results');
        if (!noResults) return;

        const visible = wrapper.querySelectorAll('.wp-pfg-card:not(.is-hidden):not(.search-hidden)').length;
        noResults.style.display = visible === 0 ? 'block' : 'none';
    }

    /**
     * Combined pipeline for a wrapper.
     */
    function runPipeline(wrapper, options) {
        options = options || {};

        if (options.sort !== false) {
            sortCards(wrapper);
        }
        if (options.filters !== false) {
            applyFilters(wrapper);
        }
        if (options.search !== false) {
            applySearch(wrapper);
        }
        updateNoResults(wrapper);
    }

    /**
     * Handle change events for sort + filter dropdowns.
     */
    document.addEventListener('change', function (e) {
        const target = e.target;
        if (!(target instanceof HTMLElement)) return;

        const wrapper = target.closest('.wp-pfg-wrapper');
        if (!wrapper) return;

        if (target.classList.contains('wp-pfg-sort-select')) {
            runPipeline(wrapper, { sort: true, filters: true, search: true });
        } else if (target.classList.contains('wp-pfg-filter-select')) {
            // Filters changed; keep sort order, but re-check filters + search
            runPipeline(wrapper, { sort: false, filters: true, search: true });
        }
    });

    /**
     * Handle keyup events for search input.
     */
    document.addEventListener('keyup', function (e) {
        const target = e.target;
        if (!(target instanceof HTMLElement)) return;

        if (!target.classList.contains('wp-pfg-search-input')) return;

        const wrapper = target.closest('.wp-pfg-wrapper');
        if (!wrapper) return;

        // Re-apply search + filters; keep current sort order
        runPipeline(wrapper, { sort: false, filters: true, search: true });
    });

    /**
     * Initial pass on DOM ready: apply default order, filters & search.
     */
    document.addEventListener('DOMContentLoaded', function () {
        const wrappers = document.querySelectorAll('.wp-pfg-wrapper');

        wrappers.forEach(wrapper => {
            runPipeline(wrapper, { sort: true, filters: true, search: true });
        });
    });

})();
