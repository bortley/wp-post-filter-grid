// WP Post Filter Grid front-end logic: sorting + filtering
(function () {
    'use strict';

    /**
     * Apply dropdown filters within a single wrapper.
     */
    function applyFilters(wrapper) {
        var dropdowns = wrapper.querySelectorAll('.wp-pfg-filter-select');
        var cards = wrapper.querySelectorAll('.wp-pfg-card');
        var activeFilters = [];

        dropdowns.forEach(function (select) {
            var val = select.value;
            if (val && val.trim() !== '') {
                activeFilters.push(val.trim());
            }
        });

        if (activeFilters.length === 0) {
            cards.forEach(function (card) {
                card.classList.remove('is-hidden');
            });
        } else {
            cards.forEach(function (card) {
                var termsAttr = card.getAttribute('data-terms') || '';
                var cardTerms = termsAttr.split(/\s+/).filter(Boolean);

                var matches = activeFilters.every(function (token) {
                    return cardTerms.indexOf(token) !== -1;
                });

                if (matches) {
                    card.classList.remove('is-hidden');
                } else {
                    card.classList.add('is-hidden');
                }
            });
        }

        // Handle "No results" message
        var noResults = wrapper.querySelector('.wp-pfg-no-results');
        if (noResults) {
            var visibleCount = wrapper.querySelectorAll('.wp-pfg-card:not(.is-hidden)').length;
            noResults.style.display = visibleCount === 0 ? 'block' : 'none';
        }
    }

    /**
     * Sort cards within a single wrapper based on selected option.
     */
    function sortCards(wrapper) {
        var sortSelect = wrapper.querySelector('.wp-pfg-sort-select');
        if (!sortSelect) return;

        var sortValue = sortSelect.value || 'default';
        var grid = wrapper.querySelector('.wp-pfg-grid');
        if (!grid) return;

        var cards = Array.prototype.slice.call(wrapper.querySelectorAll('.wp-pfg-card'));
        if (cards.length <= 1) return;

        var sorted = cards.slice();

        sorted.sort(function (a, b) {
            var aDate = parseInt(a.getAttribute('data-date') || '0', 10);
            var bDate = parseInt(b.getAttribute('data-date') || '0', 10);
            var aMod = parseInt(a.getAttribute('data-modified') || '0', 10);
            var bMod = parseInt(b.getAttribute('data-modified') || '0', 10);
            var aTitle = (a.getAttribute('data-title') || '').toLowerCase();
            var bTitle = (b.getAttribute('data-title') || '').toLowerCase();
            var aIndex = parseInt(a.getAttribute('data-index') || '0', 10);
            var bIndex = parseInt(b.getAttribute('data-index') || '0', 10);

            switch (sortValue) {
                case 'newest':
                    return bDate - aDate; // latest date first
                case 'oldest':
                    return aDate - bDate; // earliest date first
                case 'title-asc':
                    return aTitle.localeCompare(bTitle);
                case 'title-desc':
                    return bTitle.localeCompare(aTitle);
                default: // "default"
                    return aIndex - bIndex; // original order
            }
        });

        // Re-append in new order
        sorted.forEach(function (card) {
            grid.appendChild(card);
        });
    }

    /**
     * Delegated change handler for sort + filter dropdowns.
     */
    function onChange(e) {
        var target = e.target;
        if (!(target instanceof HTMLElement)) return;

        var wrapper = target.closest('.wp-pfg-wrapper');
        if (!wrapper) return;

        if (target.classList.contains('wp-pfg-sort-select')) {
            sortCards(wrapper);
            applyFilters(wrapper);
        } else if (target.classList.contains('wp-pfg-filter-select')) {
            applyFilters(wrapper);
        }
    }

    // Handle dropdown changes (sort + filter)
    document.addEventListener('change', onChange);

    // Initial pass: sort (default) + filter based on any preselected values
    document.addEventListener('DOMContentLoaded', function () {
        var wrappers = document.querySelectorAll('.wp-pfg-wrapper');

        wrappers.forEach(function (wrapper) {
            var sortSelect = wrapper.querySelector('.wp-pfg-sort-select');
            if (sortSelect) {
                sortCards(wrapper);
            }
            applyFilters(wrapper);
        });
    });

})();
