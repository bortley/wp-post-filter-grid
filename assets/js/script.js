// Wrap in IIFE to avoid polluting global scope
(function ($) {
    'use strict';

    $(document).ready(function () {
        // Cache selectors for performance
        const $filterButtons = $('.wp-pfg-filter-button');
        const $cards = $('.wp-pfg-card');

        // When a filter button is clicked
        $filterButtons.on('click', function () {
            const $btn = $(this);
            const filter = $btn.data('filter'); // e.g., "all" or "category-slug"

            // Mark the clicked button as active, deactivate others
            $filterButtons.removeClass('is-active');
            $btn.addClass('is-active');

            // If "all" is selected, show every card
            if (filter === 'all') {
                $cards.removeClass('is-hidden');
                return;
            }

            // Otherwise, show only cards that contain the selected category slug
            $cards.each(function () {
                const $card = $(this);

                // Get the categories for this card from data attribute
                const categories = $card.data('categories'); // string like "news blog-updates"

                if (!categories) {
                    // If no categories, hide the card when filtering by specific category
                    $card.addClass('is-hidden');
                    return;
                }

                // Check if the filter slug exists in the categories string
                const hasCategory = categories.split(' ').indexOf(filter) !== -1;

                if (hasCategory) {
                    $card.removeClass('is-hidden');
                } else {
                    $card.addClass('is-hidden');
                }
            });
        });
    });

})(jQuery);
