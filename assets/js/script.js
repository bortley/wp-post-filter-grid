// Global-safe, page-builder-proof filtering script
(function() {
    'use strict';

    /**
     * Apply the filters by reading dropdown values and hiding/showing post cards.
     */
    function applyFilters() {

        // 1. Collect all active filters
        const dropdowns = document.querySelectorAll('.wp-pfg-filter-select');
        const activeFilters = [];

        dropdowns.forEach(drop => {
            const val = drop.value;
            if (val && val.trim() !== '') {
                activeFilters.push(val.trim());
            }
        });

        // 2. Get all cards in the grid
        const cards = document.querySelectorAll('.wp-pfg-card');

        // 3. If no filters are selected → show everything
        if (activeFilters.length === 0) {
            cards.forEach(card => card.classList.remove('is-hidden'));
            return;
        }

        // 4. Otherwise filter each card
        cards.forEach(card => {

            const termsAttr = card.getAttribute('data-terms');
            if (!termsAttr || termsAttr.trim() === '') {
                // No term data → does not match any filter
                card.classList.add('is-hidden');
                return;
            }

            const cardTerms = termsAttr.split(/\s+/);

            // A card must match *every* active filter (AND logic)
            const matches = activeFilters.every(token => cardTerms.includes(token));

            if (matches) {
                card.classList.remove('is-hidden');
            } else {
                card.classList.add('is-hidden');
            }
        });
		
		// Show/hide "No results" message
const noResults = document.querySelector('.wp-pfg-no-results');
if (noResults) {
    const visible = document.querySelectorAll('.wp-pfg-card:not(.is-hidden)');
    if (visible.length === 0) {
        noResults.style.display = 'block';
    } else {
        noResults.style.display = 'none';
    }
}

    }


    /**
     * Guaranteed event listener for dynamically inserted dropdowns.
     * Works even if Elementor, Avada, or Gutenberg replaces <select>.
     */
    document.addEventListener('change', function(e) {
        if (e.target && e.target.classList && e.target.classList.contains('wp-pfg-filter-select')) {
            applyFilters();
        }
    });


    /**
     * Run an initial filter pass (in case dropdowns have pre-selected values)
     */
    document.addEventListener('DOMContentLoaded', function() {
        applyFilters();
    });

})();
