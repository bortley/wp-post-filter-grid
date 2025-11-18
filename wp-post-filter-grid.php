<?php
/**
 * Plugin Name: WP Post Filter Grid
 * Description: Display blog posts in a responsive grid with real-time category filters.
 * Version: 1.1.2
 * Author: MarmoAlex
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Enqueue plugin CSS and JS files.
 */
function wp_pfg_enqueue_assets() {
    wp_enqueue_style(
        'wp-pfg-style',
        plugin_dir_url( __FILE__ ) . 'assets/css/style.css',
        array(),
        '1.1.0'
    );

    wp_enqueue_script(
        'wp-pfg-script',
        plugin_dir_url( __FILE__ ) . 'assets/js/script.js',
        array( 'jquery' ),
        '1.1.0',
        true
    );
}
add_action( 'wp_enqueue_scripts', 'wp_pfg_enqueue_assets' );



/**
 * ================================
 *   ADMIN SETTINGS (EXCLUDED CATS)
 * ================================
 */

/**
 * Add a settings page under Settings → Post Filter Grid.
 */
function wp_pfg_add_settings_page() {
    add_options_page(
        'Post Filter Grid Settings',
        'Post Filter Grid',
        'manage_options',
        'wp-pfg-settings',
        'wp_pfg_render_settings_page'
    );
}
add_action( 'admin_menu', 'wp_pfg_add_settings_page' );

/**
 * Register the setting that stores excluded category IDs.
 */
function wp_pfg_register_settings() {
    // This option will store an array of category IDs (as integers).
    register_setting(
        'wp_pfg_settings_group',
        'wp_pfg_excluded_categories', // option name
        array(
            'type'              => 'array',
            'sanitize_callback' => 'wp_pfg_sanitize_excluded_categories',
            'default'           => array(),
        )
    );
}
add_action( 'admin_init', 'wp_pfg_register_settings' );

/**
 * Sanitize the excluded categories option.
 *
 * @param mixed $input The raw submitted value.
 * @return array Sanitized array of integers.
 */
function wp_pfg_sanitize_excluded_categories( $input ) {
    $sanitized = array();

    if ( is_array( $input ) ) {
        foreach ( $input as $cat_id ) {
            $sanitized[] = (int) $cat_id;
        }
    }

    return $sanitized;
}

/**
 * Render the settings page HTML.
 */
function wp_pfg_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Get currently saved excluded categories (array of IDs).
    $excluded_cats = get_option( 'wp_pfg_excluded_categories', array() );

    // Get all categories.
    $categories = get_categories( array(
        'hide_empty' => false,
    ) );
    ?>
    <div class="wrap">
        <h1>Post Filter Grid Settings</h1>

        <form method="post" action="options.php">
            <?php
            // Output security fields for the registered setting.
            settings_fields( 'wp_pfg_settings_group' );
            do_settings_sections( 'wp_pfg_settings_group' );
            ?>

            <h2>Exclude Categories</h2>
            <p>Select any categories you <strong>do not</strong> want to appear in:</p>
            <ul style="list-style: none; padding-left: 0;">
                <li>• The filter buttons at the top of the grid</li>
                <li>• The posts displayed in the grid</li>
            </ul>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Categories to exclude</th>
                    <td>
                        <?php if ( ! empty( $categories ) ) : ?>
                            <div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 8px;">
                                <?php foreach ( $categories as $cat ) : ?>
                                    <?php
                                    $checked = in_array( $cat->term_id, (array) $excluded_cats, true ) ? 'checked="checked"' : '';
                                    ?>
                                    <label style="display: block; margin-bottom: 4px;">
                                        <input
                                            type="checkbox"
                                            name="wp_pfg_excluded_categories[]"
                                            value="<?php echo esc_attr( $cat->term_id ); ?>"
                                            <?php echo $checked; ?>
                                        />
                                        <?php echo esc_html( $cat->name ); ?> (ID: <?php echo (int) $cat->term_id; ?>)
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <p>No categories found.</p>
                        <?php endif; ?>
                        <p class="description">Legacy / unused categories can be checked here so they never show up in the front-end filter grid.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}


/**
 * Convert a comma-separated list of category slugs into an array of category IDs.
 *
 * Example: "news, blog,updates" => array( 12, 34, 56 )
 *
 * @param string $slugs_string Comma-separated list of category slugs.
 * @return array Array of category IDs.
 */
function wp_pfg_get_category_ids_from_slugs( $slugs_string ) {
    // If nothing was passed, return an empty array.
    if ( empty( $slugs_string ) ) {
        return array();
    }

    // Split the string on commas into an array.
    $slugs = explode( ',', $slugs_string );

    $ids = array();

    foreach ( $slugs as $slug ) {
        // Trim whitespace around each slug.
        $slug = trim( $slug );

        if ( $slug === '' ) {
            continue;
        }

        // Look up the category term by slug.
        $term = get_category_by_slug( $slug );

        // If a category was found, store its term_id.
        if ( $term && ! is_wp_error( $term ) ) {
            $ids[] = (int) $term->term_id;
        }
    }

    // Remove duplicates just in case.
    $ids = array_unique( $ids );

    return $ids;
}



/**
 * Shortcode: [wp_post_filter_grid]
 *
 * Attributes:
 * - posts_per_page: int, number of posts to show (default 12)
 * - include_cats: comma-separated list of category slugs to include (optional)
 * - exclude_cats: comma-separated list of category slugs to exclude (optional)
 *
 * Example:
 * [wp_post_filter_grid posts_per_page="9" include_cats="news,blog" exclude_cats="legacy"]
 */
function wp_pfg_render_shortcode( $atts ) {
    // Merge user-defined attributes with defaults.
    $atts = shortcode_atts(
        array(
            'posts_per_page' => 12,
            'include_cats'   => '', // comma-separated category slugs to include
            'exclude_cats'   => '', // comma-separated category slugs to exclude
        ),
        $atts,
        'wp_post_filter_grid'
    );

    /**
     * 1) Get the globally excluded categories from settings.
     *    These are your "never show these anywhere" categories.
     */
    $global_excluded_cats = get_option( 'wp_pfg_excluded_categories', array() );
    if ( ! is_array( $global_excluded_cats ) ) {
        $global_excluded_cats = array();
    }

    /**
     * 2) Convert shortcode include/exclude slug lists into arrays of IDs.
     */
    $include_cats_ids  = wp_pfg_get_category_ids_from_slugs( $atts['include_cats'] );
    $exclude_cats_ids  = wp_pfg_get_category_ids_from_slugs( $atts['exclude_cats'] );

    /**
     * 3) Combine global excluded categories with the shortcode-specific excluded ones.
     *    Global exclusions always apply (they "win" over includes).
     */
    $all_excluded_cats = array_unique(
        array_merge(
            $global_excluded_cats,
            $exclude_cats_ids
        )
    );

    /**
     * 4) Build the base WP_Query arguments.
     */
    $query_args = array(
        'post_type'      => 'post',
        'posts_per_page' => intval( $atts['posts_per_page'] ),
        'post_status'    => 'publish',
    );

    // If we have categories to include, force posts to be in those categories.
    if ( ! empty( $include_cats_ids ) ) {
        $query_args['category__in'] = $include_cats_ids;
    }

    // If we have categories to exclude (global + shortcode), exclude them from the query.
    if ( ! empty( $all_excluded_cats ) ) {
        $query_args['category__not_in'] = $all_excluded_cats;
    }

    // Run the query.
    $query = new WP_Query( $query_args );

    if ( ! $query->have_posts() ) {
        return '<p>No posts found.</p>';
    }

    /**
     * 5) Loop through posts to build:
     *    - $posts_data: the card data
     *    - $categories_used: the set of categories for filter buttons
     */
    $categories_used = array();
    $posts_data      = array();

    while ( $query->have_posts() ) {
        $query->the_post();

        $post_categories = get_the_category( get_the_ID() );
        $cat_slugs       = array();

        if ( ! empty( $post_categories ) ) {
            foreach ( $post_categories as $cat ) {
                // Skip categories that are excluded either globally or by shortcode.
                if ( in_array( $cat->term_id, $all_excluded_cats, true ) ) {
                    continue;
                }

                $cat_slugs[] = esc_attr( $cat->slug );
                $categories_used[ $cat->slug ] = $cat->name;
            }
        }

        $posts_data[] = array(
            'ID'        => get_the_ID(),
            'title'     => get_the_title(),
            'excerpt'   => get_the_excerpt(),
            'permalink' => get_permalink(),
            'thumbnail' => get_the_post_thumbnail( get_the_ID(), 'medium' ),
            'cats'      => $cat_slugs,
        );
    }

    // Restore global $post.
    wp_reset_postdata();

    ob_start();
    ?>
    <div class="wp-pfg-wrapper">

        <!-- Filter Buttons -->
        <div class="wp-pfg-filters">
            <button class="wp-pfg-filter-button is-active" data-filter="all">
                All
            </button>

            <?php foreach ( $categories_used as $slug => $name ) : ?>
                <button class="wp-pfg-filter-button" data-filter="<?php echo esc_attr( $slug ); ?>">
                    <?php echo esc_html( $name ); ?>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- Post Grid -->
        <div class="wp-pfg-grid">
            <?php foreach ( $posts_data as $post ) : ?>
                <?php
                // Build the space-separated class list for this card's categories.
                $cat_classes = '';
                if ( ! empty( $post['cats'] ) ) {
                    $cat_classes = implode( ' ', $post['cats'] );
                }
                ?>
                <article class="wp-pfg-card" data-categories="<?php echo esc_attr( $cat_classes ); ?>">
                    <a href="<?php echo esc_url( $post['permalink'] ); ?>" class="wp-pfg-card-inner">

                        <?php if ( ! empty( $post['thumbnail'] ) ) : ?>
                            <div class="wp-pfg-card-image">
                                <?php echo $post['thumbnail']; ?>
                            </div>
                        <?php endif; ?>

                        <div class="wp-pfg-card-content">
                            <h3 class="wp-pfg-card-title">
                                <?php echo esc_html( $post['title'] ); ?>
                            </h3>

                            <p class="wp-pfg-card-excerpt">
                                <?php echo esc_html( wp_trim_words( $post['excerpt'], 20, '…' ) ); ?>
                            </p>

                            <span class="wp-pfg-card-readmore">
                                Read more →
                            </span>
                        </div>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
    <?php

    return ob_get_clean();
}
add_shortcode( 'wp_post_filter_grid', 'wp_pfg_render_shortcode' );

