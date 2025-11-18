// Wrap in IIFE to avoid polluting global scope
(function ($) {
    'use strict';

    $(document).ready(function () {
        // Dropdowns and cards
        const $dropdowns = $('.wp-pfg-filter-select');
        const $cards = $('.wp-pfg-card');

        /**
         * Apply filters based on the currently selected dropdown values.
         */
        function applyFilters() {
            const activeFilters = [];

            // Collect selected values (ignoring "All" / empty)
            $dropdowns.each(function () {
                const value = $(this).val();
                if (value) {
                    activeFilters.push(String(value));
                }
            });

            // If no filters selected, show everything
            if (activeFilters.length === 0) {
                $cards.removeClass('is-hidden');
                return;
            }

            // Otherwise, check each card against all active filters (AND logic)
            $cards.each(function () {
                const $card = $(this);
                const termsAttr = $card.data('terms');

                if (!termsAttr) {
                    // If card has no term data and filters are active, hide it
                    $card.addClass('is-hidden');
                    return;
                }

                const cardTerms = String(termsAttr).split(' ');

                // Card must contain *every* active filter token
                const matches = activeFilters.every(function (token) {
                    return cardTerms.indexOf(token) !== -1;
                });

                if (matches) {
                    $card.removeClass('is-hidden');
                } else {
                    $card.addClass('is-hidden');
                }
            });
        }

        // Bind change event
        $dropdowns.on('change', applyFilters);
    });

})(jQuery);
