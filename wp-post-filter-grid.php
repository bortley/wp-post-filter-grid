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
    // Existing option: excluded category IDs.
    register_setting(
        'wp_pfg_settings_group',
        'wp_pfg_excluded_categories', // option name
        array(
            'type'              => 'array',
            'sanitize_callback' => 'wp_pfg_sanitize_excluded_categories',
            'default'           => array(),
        )
    );

    // NEW: option for dropdown filter definitions.
    // This will be an array of dropdown configs.
    register_setting(
        'wp_pfg_settings_group',
        'wp_pfg_filter_dropdowns',
        array(
            'type'              => 'array',
            'sanitize_callback' => 'wp_pfg_sanitize_filter_dropdowns',
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
 * Sanitize the filter dropdown configuration.
 *
 * Structure:
 * [
 *   [
 *     'label'    => (string),
 *     'taxonomy' => 'category'|'post_tag',
 *     'terms'    => [int term IDs...],
 *   ],
 *   ...
 * ]
 *
 * @param mixed $input Raw input from the settings form.
 * @return array Sanitized config.
 */
function wp_pfg_sanitize_filter_dropdowns( $input ) {
    $sanitized = array();

    if ( ! is_array( $input ) ) {
        return $sanitized;
    }

    foreach ( $input as $dropdown ) {
        // If nothing is set, skip it (acts like "disabled").
        if (
            ( empty( $dropdown['label'] ) || ! is_string( $dropdown['label'] ) ) &&
            ( empty( $dropdown['terms'] ) || ! is_array( $dropdown['terms'] ) )
        ) {
            continue;
        }

        $label = isset( $dropdown['label'] )
            ? sanitize_text_field( $dropdown['label'] )
            : '';

        $allowed_taxonomies = array( 'category', 'post_tag' );
        $taxonomy = isset( $dropdown['taxonomy'] ) && in_array( $dropdown['taxonomy'], $allowed_taxonomies, true )
            ? $dropdown['taxonomy']
            : 'category';

        $terms = array();
        if ( isset( $dropdown['terms'] ) && is_array( $dropdown['terms'] ) ) {
            foreach ( $dropdown['terms'] as $term_id ) {
                $term_id = (int) $term_id;
                if ( $term_id > 0 ) {
                    $terms[] = $term_id;
                }
            }
            $terms = array_values( array_unique( $terms ) );
        }

        $sanitized[] = array(
            'label'    => $label,
            'taxonomy' => $taxonomy,
            'terms'    => $terms,
        );
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

$categories = get_categories( array(
    'hide_empty' => false,
) );

// NEW: get tags (for tag-based dropdowns).
$tags = get_terms(
    array(
        'taxonomy'   => 'post_tag',
        'hide_empty' => false,
    )
);

// NEW: current dropdown configuration.
$dropdowns = get_option( 'wp_pfg_filter_dropdowns', array() );
if ( ! is_array( $dropdowns ) ) {
    $dropdowns = array();
}
?>
<div class="wrap">
    <h1>Post Filter Grid Settings</h1>

    <form method="post" action="options.php">
        <?php
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
                        <!-- Existing "Exclude Categories" table ends above -->

            <hr />

            <h2>Filter Dropdowns</h2>
            <p>
                Here you can define up to <strong>3 dropdown filters</strong> for the grid.
                Each dropdown can target <em>categories</em> or <em>tags</em>, and you get to
                choose the specific terms that appear in it.
            </p>
            <p>
                <strong>Note:</strong> Leave the "Dropdown label" empty to disable that dropdown.
            </p>

            <?php
            // We'll allow up to 3 dropdowns for now.
            $max_dropdowns = 3;

            for ( $i = 0; $i < $max_dropdowns; $i++ ) :

                $current = isset( $dropdowns[ $i ] ) && is_array( $dropdowns[ $i ] )
                    ? $dropdowns[ $i ]
                    : array();

                $label          = isset( $current['label'] ) ? $current['label'] : '';
                $taxonomy       = isset( $current['taxonomy'] ) ? $current['taxonomy'] : 'category';
                $selected_terms = isset( $current['terms'] ) && is_array( $current['terms'] )
                    ? $current['terms']
                    : array();
            ?>
                <h3>Dropdown <?php echo ( $i + 1 ); ?></h3>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="wp_pfg_filter_dropdowns_<?php echo $i; ?>_label">
                                Dropdown label
                            </label>
                        </th>
                        <td>
                            <input
                                type="text"
                                class="regular-text"
                                id="wp_pfg_filter_dropdowns_<?php echo $i; ?>_label"
                                name="wp_pfg_filter_dropdowns[<?php echo $i; ?>][label]"
                                value="<?php echo esc_attr( $label ); ?>"
                                placeholder="e.g. Topic, Content Type, Tag"
                            />
                            <p class="description">
                                Leave blank to disable this dropdown.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            Taxonomy
                        </th>
                        <td>
                            <select
                                name="wp_pfg_filter_dropdowns[<?php echo $i; ?>][taxonomy]"
                            >
                                <option value="category" <?php selected( $taxonomy, 'category' ); ?>>
                                    Categories
                                </option>
                                <option value="post_tag" <?php selected( $taxonomy, 'post_tag' ); ?>>
                                    Tags
                                </option>
                            </select>
                            <p class="description">
                                Change this and click "Save Changes", then reload to see the correct term list.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            Terms to include
                        </th>
                        <td>
                            <?php if ( 'category' === $taxonomy ) : ?>

                                <?php if ( ! empty( $categories ) ) : ?>
                                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 8px;">
                                        <?php foreach ( $categories as $cat ) : ?>
                                            <?php
                                            $checked = in_array( (int) $cat->term_id, $selected_terms, true )
                                                ? 'checked="checked"'
                                                : '';
                                            ?>
                                            <label style="display: block; margin-bottom: 4px;">
                                                <input
                                                    type="checkbox"
                                                    name="wp_pfg_filter_dropdowns[<?php echo $i; ?>][terms][]"
                                                    value="<?php echo esc_attr( $cat->term_id ); ?>"
                                                    <?php echo $checked; ?>
                                                />
                                                <?php echo esc_html( $cat->name ); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else : ?>
                                    <p>No categories found.</p>
                                <?php endif; ?>

                            <?php else : // post_tag ?>

                                <?php if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) : ?>
                                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 8px;">
                                        <?php foreach ( $tags as $tag ) : ?>
                                            <?php
                                            $checked = in_array( (int) $tag->term_id, $selected_terms, true )
                                                ? 'checked="checked"'
                                                : '';
                                            ?>
                                            <label style="display: block; margin-bottom: 4px;">
                                                <input
                                                    type="checkbox"
                                                    name="wp_pfg_filter_dropdowns[<?php echo $i; ?>][terms][]"
                                                    value="<?php echo esc_attr( $tag->term_id ); ?>"
                                                    <?php echo $checked; ?>
                                                />
                                                <?php echo esc_html( $tag->name ); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else : ?>
                                    <p>No tags found.</p>
                                <?php endif; ?>

                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            <?php endfor; ?>

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

    // --- Categories ---
    $post_categories = get_the_category( get_the_ID() );
    $cat_slugs       = array();

    if ( ! empty( $post_categories ) ) {
        foreach ( $post_categories as $cat ) {
            // Skip categories that are excluded either globally or by shortcode.
            if ( in_array( $cat->term_id, $all_excluded_cats, true ) ) {
                continue;
            }

            $slug = esc_attr( $cat->slug );
            $cat_slugs[] = $slug;

            // Still track this if you want a list of used categories elsewhere.
            $categories_used[ $cat->slug ] = $cat->name;
        }
    }

    // --- Tags ---
    $post_tags = get_the_terms( get_the_ID(), 'post_tag' );
    $tag_slugs = array();

    if ( ! empty( $post_tags ) && ! is_wp_error( $post_tags ) ) {
        foreach ( $post_tags as $tag ) {
            $tag_slugs[] = esc_attr( $tag->slug );
        }
    }

    // --- Combined tokens for front-end filtering ---
    // We prefix with taxonomy so "news" category vs "news" tag don't collide.
    $term_tokens = array();

    foreach ( $cat_slugs as $slug ) {
        $term_tokens[] = 'category:' . $slug;
    }

    foreach ( $tag_slugs as $slug ) {
        $term_tokens[] = 'post_tag:' . $slug;
    }

    $posts_data[] = array(
        'ID'        => get_the_ID(),
        'title'     => get_the_title(),
        'excerpt'   => get_the_excerpt(),
        'permalink' => get_permalink(),
        'thumbnail' => get_the_post_thumbnail( get_the_ID(), 'medium' ),
        'cats'      => $cat_slugs,
        'tags'      => $tag_slugs,
        'terms'     => $term_tokens,
    );
}


    // Restore global $post.
// Restore global $post.
wp_reset_postdata();

/**
 * Build dropdown data for the front-end from the saved settings.
 */
$raw_dropdowns        = get_option( 'wp_pfg_filter_dropdowns', array() );
$dropdowns_for_render = array();

if ( is_array( $raw_dropdowns ) ) {
    foreach ( $raw_dropdowns as $dropdown ) {
        $label    = isset( $dropdown['label'] ) ? trim( $dropdown['label'] ) : '';
        $taxonomy = isset( $dropdown['taxonomy'] ) ? $dropdown['taxonomy'] : 'category';
        $terms    = isset( $dropdown['terms'] ) && is_array( $dropdown['terms'] ) ? $dropdown['terms'] : array();

        if ( '' === $label || empty( $terms ) ) {
            // Skip empty or disabled dropdowns.
            continue;
        }

        $term_data = array();

        foreach ( $terms as $term_id ) {
            $term_id = (int) $term_id;
            if ( $term_id <= 0 ) {
                continue;
            }

            $term = get_term( $term_id );
            if ( $term instanceof WP_Term && ! is_wp_error( $term ) ) {
                $token = $term->taxonomy . ':' . $term->slug;

                $term_data[] = array(
                    'id'    => $term->term_id,
                    'slug'  => $term->slug,
                    'name'  => $term->name,
                    'token' => $token,
                );
            }
        }

        if ( ! empty( $term_data ) ) {
            $dropdowns_for_render[] = array(
                'label'    => $label,
                'taxonomy' => $taxonomy,
                'terms'    => $term_data,
            );
        }
    }
}

ob_start();
?>
<div class="wp-pfg-wrapper">


    <!-- Filter Dropdowns -->
    <?php if ( ! empty( $dropdowns_for_render ) ) : ?>
        <div class="wp-pfg-filters">
            <?php foreach ( $dropdowns_for_render as $dropdown ) : ?>
                <label class="wp-pfg-filter-dropdown">
                    <span class="wp-pfg-filter-label">
                        <?php echo esc_html( $dropdown['label'] ); ?>
                    </span>

                    <select
                        class="wp-pfg-filter-select"
                        data-taxonomy="<?php echo esc_attr( $dropdown['taxonomy'] ); ?>"
                    >
                        <option value="">
                            <?php esc_html_e( 'All', 'wp-pfg' ); ?>
                        </option>

                        <?php foreach ( $dropdown['terms'] as $term ) : ?>
                            <option value="<?php echo esc_attr( $term['token'] ); ?>">
                                <?php echo esc_html( $term['name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>


<!-- Post Grid -->
<div class="wp-pfg-grid">
    <?php foreach ( $posts_data as $post ) : ?>
        <?php
        // Space-separated categories.
        $cat_classes = '';
        if ( ! empty( $post['cats'] ) ) {
            $cat_classes = implode( ' ', $post['cats'] );
        }

        // Space-separated tokens like "category:news post_tag:featured".
        $term_tokens_attr = '';
        if ( ! empty( $post['terms'] ) ) {
            $term_tokens_attr = implode( ' ', $post['terms'] );
        }
        ?>
        <article
            class="wp-pfg-card"
            data-categories="<?php echo esc_attr( $cat_classes ); ?>"
            data-terms="<?php echo esc_attr( $term_tokens_attr ); ?>"
        >

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

