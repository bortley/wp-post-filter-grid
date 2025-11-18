<?php
/**
 * Plugin Name: WP Post Filter Grid
 * Description: Display blog posts in a responsive grid with real-time taxonomy-based filters. Supports multiple configurations via shortcode IDs.
 * Version: 3.2.0
 * Author: MarmoAlex
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ===================================
 *   FRONT-END SCRIPTS & STYLES
 * ===================================
 */
function wp_pfg_enqueue_assets() {
    wp_enqueue_style(
        'wp-pfg-style',
        plugin_dir_url( __FILE__ ) . 'assets/css/style.css',
        array(),
        '3.2.0'
    );

    wp_enqueue_script(
        'wp-pfg-script',
        plugin_dir_url( __FILE__ ) . 'assets/js/script.js',
        array(), // vanilla JS only
        '3.2.0',
        true
    );
}
add_action( 'wp_enqueue_scripts', 'wp_pfg_enqueue_assets' );


/**
 * ===================================
 *   SANITIZATION HELPERS
 * ===================================
 */
function wp_pfg_sanitize_config_ids( $input ) {
    if ( ! is_array( $input ) ) {
        return array();
    }

    $out = array();
    foreach ( $input as $id ) {
        $id = sanitize_key( $id );
        if ( $id !== '' && $id !== 'default' ) {
            $out[] = $id;
        }
    }

    return array_values( array_unique( $out ) );
}

function wp_pfg_sanitize_category_ids( $input ) {
    if ( ! is_array( $input ) ) {
        return array();
    }
    return array_map( 'intval', $input );
}

function wp_pfg_sanitize_dropdowns( $input ) {
    if ( ! is_array( $input ) ) {
        return array();
    }

    $out = array();

    foreach ( $input as $dropdown ) {
        if ( empty( $dropdown['label'] ) ) {
            continue;
        }

        $label    = sanitize_text_field( $dropdown['label'] );
        $taxonomy = isset( $dropdown['taxonomy'] ) && in_array( $dropdown['taxonomy'], array( 'category', 'post_tag' ), true )
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
        }

        $out[] = array(
            'label'    => $label,
            'taxonomy' => $taxonomy,
            'terms'    => array_values( array_unique( $terms ) ),
        );
    }

    return $out;
}


/**
 * ===================================
 *   REGISTER SETTINGS
 * ===================================
 */
function wp_pfg_register_settings() {

    // Index of non-default configs
    register_setting(
        'wp_pfg_settings_group',
        'wp_pfg_config_ids',
        array(
            'type'              => 'array',
            'sanitize_callback' => 'wp_pfg_sanitize_config_ids',
            'default'           => array(),
        )
    );

    // Default config
    register_setting(
        'wp_pfg_settings_group',
        'wp_pfg_included_categories',
        array(
            'type'              => 'array',
            'sanitize_callback' => 'wp_pfg_sanitize_category_ids',
            'default'           => array(),
        )
    );

    register_setting(
        'wp_pfg_settings_group',
        'wp_pfg_filter_dropdowns',
        array(
            'type'              => 'array',
            'sanitize_callback' => 'wp_pfg_sanitize_dropdowns',
            'default'           => array(),
        )
    );

    // Config-specific options
    $config_ids = get_option( 'wp_pfg_config_ids', array() );
    if ( is_array( $config_ids ) ) {
        foreach ( $config_ids as $config_id ) {
            $id = sanitize_key( $config_id );
            if ( ! $id ) {
                continue;
            }

            register_setting(
                'wp_pfg_settings_group',
                'wp_pfg_included_categories_' . $id,
                array(
                    'type'              => 'array',
                    'sanitize_callback' => 'wp_pfg_sanitize_category_ids',
                    'default'           => array(),
                )
            );

            register_setting(
                'wp_pfg_settings_group',
                'wp_pfg_filter_dropdowns_' . $id,
                array(
                    'type'              => 'array',
                    'sanitize_callback' => 'wp_pfg_sanitize_dropdowns',
                    'default'           => array(),
                )
            );
        }
    }
}
add_action( 'admin_init', 'wp_pfg_register_settings' );


/**
 * ===================================
 *   ADMIN MENUS
 * ===================================
 */
function wp_pfg_add_settings_menu() {
    // Default config page
    add_options_page(
        'Post Filter Grid (Default)',
        'Post Filter Grid',
        'manage_options',
        'wp-pfg-default',
        'wp_pfg_render_default_page'
    );

    // Config-specific pages
    $config_ids = get_option( 'wp_pfg_config_ids', array() );
    if ( is_array( $config_ids ) ) {
        foreach ( $config_ids as $config_id ) {
            $slug = sanitize_key( $config_id );
            if ( ! $slug ) {
                continue;
            }

            add_options_page(
                'PFG – ' . ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ),
                'PFG – ' . ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ),
                'manage_options',
                'wp-pfg-settings-' . $slug,
                function () use ( $slug ) {
                    wp_pfg_render_config_page( $slug );
                }
            );
        }
    }
}
add_action( 'admin_menu', 'wp_pfg_add_settings_menu' );


/**
 * ===================================
 *   DEFAULT CONFIG PAGE
 * ===================================
 */
function wp_pfg_render_default_page() {

    // Handle creation of a new configuration
    if ( isset( $_POST['wp_pfg_new_config_id'] ) ) {
        check_admin_referer( 'wp_pfg_add_config', 'wp_pfg_add_config_nonce' );

        $new = sanitize_key( wp_unslash( $_POST['wp_pfg_new_config_id'] ) );
        if ( $new && 'default' !== $new ) {
            $configs   = get_option( 'wp_pfg_config_ids', array() );
            $configs[] = $new;
            $configs   = array_values( array_unique( $configs ) );
            update_option( 'wp_pfg_config_ids', $configs );

            // Initialize options for new config
            update_option( 'wp_pfg_included_categories_' . $new, array() );
            update_option( 'wp_pfg_filter_dropdowns_' . $new, array() );

            wp_safe_redirect( admin_url( 'options-general.php?page=wp-pfg-settings-' . $new ) );
            exit;
        }
    }

    $config_ids = get_option( 'wp_pfg_config_ids', array() );
    ?>
    <div class="wrap">
        <h1>Post Filter Grid — Default Configuration</h1>

        <h2>Existing Configurations</h2>
        <ul>
            <?php if ( ! empty( $config_ids ) ) : ?>
                <?php foreach ( $config_ids as $id ) : ?>
                    <li>
                        <a href="<?php echo esc_url( admin_url( 'options-general.php?page=wp-pfg-settings-' . $id ) ); ?>">
                            <?php echo esc_html( ucwords( str_replace( array( '-', '_' ), ' ', $id ) ) ); ?>
                        </a>
                        &nbsp;<code>[wp_post_filter_grid id="<?php echo esc_attr( $id ); ?>"]</code>
                    </li>
                <?php endforeach; ?>
            <?php else : ?>
                <li><em>No additional configurations created yet.</em></li>
            <?php endif; ?>
        </ul>

        <h2>Add New Configuration</h2>
        <form method="post">
            <?php wp_nonce_field( 'wp_pfg_add_config', 'wp_pfg_add_config_nonce' ); ?>
            <p>
                <label>
                    Configuration ID (lowercase, no spaces; dashes/underscores OK):<br />
                    <input type="text" name="wp_pfg_new_config_id" class="regular-text" required />
                </label>
            </p>
            <p><button type="submit" class="button button-primary">Create Configuration</button></p>
        </form>

        <hr />

        <?php wp_pfg_render_settings_form( 'default' ); ?>
    </div>
    <?php
}


/**
 * ===================================
 *   CONFIG-SPECIFIC SETTINGS PAGE
 * ===================================
 */
function wp_pfg_render_config_page( $config_id ) {
    ?>
    <div class="wrap">
        <h1>
            Post Filter Grid — 
            <?php echo esc_html( ucwords( str_replace( array( '-', '_' ), ' ', $config_id ) ) ); ?>
        </h1>

        <?php wp_pfg_render_settings_form( $config_id ); ?>
    </div>
    <?php
}


/**
 * ===================================
 *   UNIVERSAL SETTINGS FORM
 * ===================================
 */
function wp_pfg_render_settings_form( $config_id ) {

    if ( 'default' === $config_id ) {
        $included_option = 'wp_pfg_included_categories';
        $dropdown_option = 'wp_pfg_filter_dropdowns';
    } else {
        $included_option = 'wp_pfg_included_categories_' . $config_id;
        $dropdown_option = 'wp_pfg_filter_dropdowns_' . $config_id;
    }

    $included  = get_option( $included_option, array() );
    $dropdowns = get_option( $dropdown_option, array() );

    if ( ! is_array( $included ) ) {
        $included = array();
    }
    if ( ! is_array( $dropdowns ) ) {
        $dropdowns = array();
    }

    $categories = get_categories( array( 'hide_empty' => false ) );
    $tags       = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );
    ?>
    <form method="post" action="options.php">
        <?php settings_fields( 'wp_pfg_settings_group' ); ?>

        <h2>Include Categories</h2>
        <p>Select categories to include for this configuration. Leave empty to include all categories.</p>

        <div style="max-height:220px; overflow-y:auto; border:1px solid #ddd; padding:8px; background:#fff;">
            <?php if ( ! empty( $categories ) ) : ?>
                <?php foreach ( $categories as $cat ) : ?>
                    <label style="display:block; margin-bottom:4px;">
                        <input
                            type="checkbox"
                            name="<?php echo esc_attr( $included_option ); ?>[]"
                            value="<?php echo esc_attr( $cat->term_id ); ?>"
                            <?php checked( in_array( $cat->term_id, $included, true ) ); ?>
                        />
                        <?php echo esc_html( $cat->name ); ?>
                    </label>
                <?php endforeach; ?>
            <?php else : ?>
                <em>No categories found.</em>
            <?php endif; ?>
        </div>

        <hr />

        <h2>Filter Dropdowns</h2>
        <p>Create any number of dropdown filters (categories or tags) with specific terms.</p>

        <div id="wp-pfg-dropdowns-container">
            <?php if ( ! empty( $dropdowns ) ) : ?>
                <?php foreach ( $dropdowns as $i => $dropdown ) : ?>
                    <?php
                    $label    = isset( $dropdown['label'] ) ? $dropdown['label'] : '';
                    $taxonomy = isset( $dropdown['taxonomy'] ) ? $dropdown['taxonomy'] : 'category';
                    $terms    = isset( $dropdown['terms'] ) && is_array( $dropdown['terms'] ) ? $dropdown['terms'] : array();

                    $term_list = ( 'post_tag' === $taxonomy ) ? $tags : $categories;
                    ?>
                    <div class="wp-pfg-dropdown-block" style="padding:12px; margin-bottom:16px; border:1px solid #ddd; background:#f9f9f9;">
                        <h3>Dropdown <?php echo (int) ( $i + 1 ); ?></h3>

                        <p>
                            <label>
                                Label:<br />
                                <input
                                    type="text"
                                    class="regular-text"
                                    name="<?php echo esc_attr( $dropdown_option ); ?>[<?php echo (int) $i; ?>][label]"
                                    value="<?php echo esc_attr( $label ); ?>"
                                />
                            </label>
                        </p>

                        <p>
                            <label>
                                Taxonomy:<br />
                                <select name="<?php echo esc_attr( $dropdown_option ); ?>[<?php echo (int) $i; ?>][taxonomy]">
                                    <option value="category" <?php selected( $taxonomy, 'category' ); ?>>Categories</option>
                                    <option value="post_tag" <?php selected( $taxonomy, 'post_tag' ); ?>>Tags</option>
                                </select>
                            </label>
                        </p>

                        <p>Terms:</p>
                        <div style="max-height:200px; overflow-y:auto; border:1px solid #ddd; padding:8px; background:#fff;">
                            <?php
                            if ( ! empty( $term_list ) && ! is_wp_error( $term_list ) ) :
                                foreach ( $term_list as $term ) :
                                    $checked = in_array( $term->term_id, $terms, true ) ? 'checked="checked"' : '';
                                    ?>
                                    <label style="display:block; margin-bottom:4px;">
                                        <input
                                            type="checkbox"
                                            name="<?php echo esc_attr( $dropdown_option ); ?>[<?php echo (int) $i; ?>][terms][]"
                                            value="<?php echo esc_attr( $term->term_id ); ?>"
                                            <?php echo $checked; ?>
                                        />
                                        <?php echo esc_html( $term->name ); ?>
                                    </label>
                                    <?php
                                endforeach;
                            else :
                                ?>
                                <em>No terms available for this taxonomy.</em>
                            <?php endif; ?>
                        </div>

                        <p>
                            <button type="button" class="button wp-pfg-remove-dropdown">Remove</button>
                        </p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <p>
            <button type="button" class="button button-primary" id="wp-pfg-add-dropdown">
                Add Dropdown
            </button>
        </p>

        <?php submit_button(); ?>
    </form>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var container  = document.getElementById('wp-pfg-dropdowns-container');
        var addBtn     = document.getElementById('wp-pfg-add-dropdown');
        var optionName = <?php echo wp_json_encode( $dropdown_option ); ?>;

        if (!container || !addBtn) return;

        addBtn.addEventListener('click', function () {
            var index = container.querySelectorAll('.wp-pfg-dropdown-block').length;

            var html = ''
                + '<div class="wp-pfg-dropdown-block" style="padding:12px; margin-bottom:16px; border:1px solid #ddd; background:#f9f9f9;">'
                + '  <h3>Dropdown ' + (index + 1) + '</h3>'
                + '  <p>'
                + '    <label>Label:<br />'
                + '      <input type="text" class="regular-text" name="' + optionName + '[' + index + '][label]" />'
                + '    </label>'
                + '  </p>'
                + '  <p>'
                + '    <label>Taxonomy:<br />'
                + '      <select name="' + optionName + '[' + index + '][taxonomy]">'
                + '        <option value="category">Categories</option>'
                + '        <option value="post_tag">Tags</option>'
                + '      </select>'
                + '    </label>'
                + '  </p>'
                + '  <p>Terms:</p>'
                + '  <div style="max-height:200px; overflow-y:auto; border:1px solid #ddd; padding:8px; background:#fff;">'
                + '    <em>Save to load terms for the selected taxonomy.</em>'
                + '  </div>'
                + '  <p><button type="button" class="button wp-pfg-remove-dropdown">Remove</button></p>'
                + '</div>';

            container.insertAdjacentHTML('beforeend', html);
        });

        container.addEventListener('click', function (e) {
            if (e.target.classList.contains('wp-pfg-remove-dropdown')) {
                var block = e.target.closest('.wp-pfg-dropdown-block');
                if (block) {
                    block.remove();
                }
            }
        });
    });
    </script>
    <?php
}


/**
 * ===================================
 *   HELPER: CATEGORY SLUGS → IDs
 * ===================================
 */
function wp_pfg_get_category_ids_from_slugs( $string ) {
    $ids   = array();
    $slugs = array_filter( array_map( 'trim', explode( ',', $string ) ) );

    if ( empty( $slugs ) ) {
        return $ids;
    }

    foreach ( $slugs as $slug ) {
        $term = get_category_by_slug( $slug );
        if ( $term && ! is_wp_error( $term ) ) {
            $ids[] = (int) $term->term_id;
        }
    }

    return $ids;
}


/**
 * ===================================
 *   SHORTCODE FRONT-END OUTPUT
 * ===================================
 *
 * Usage:
 * [wp_post_filter_grid id="tutorials" posts_per_page="12" include_cats="news,updates"]
 */
function wp_pfg_render_shortcode( $atts ) {

    $atts = shortcode_atts(
        array(
            'posts_per_page' => 12,
            'include_cats'   => '',
            'id'             => '',
        ),
        $atts,
        'wp_post_filter_grid'
    );

    $config_id = sanitize_key( $atts['id'] );

    // Determine options for this config
    if ( $config_id ) {
        $included_option = 'wp_pfg_included_categories_' . $config_id;
        $dropdown_option = 'wp_pfg_filter_dropdowns_' . $config_id;
    } else {
        $included_option = 'wp_pfg_included_categories';
        $dropdown_option = 'wp_pfg_filter_dropdowns';
    }

    // Config-level includes
    $config_included = get_option( $included_option, array() );
    if ( ! is_array( $config_included ) ) {
        $config_included = array();
    }

    // Shortcode-level include_cats
    $shortcode_included = wp_pfg_get_category_ids_from_slugs( $atts['include_cats'] );

    $all_included = array_unique(
        array_filter(
            array_map(
                'intval',
                array_merge( $config_included, $shortcode_included )
            )
        )
    );

    // Query posts
    $query_args = array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => (int) $atts['posts_per_page'],
    );

    if ( ! empty( $all_included ) ) {
        $query_args['category__in'] = $all_included;
    }

    $query = new WP_Query( $query_args );
    if ( ! $query->have_posts() ) {
        return '<p>No posts found.</p>';
    }

    $posts = array();
    $index = 0;

    while ( $query->have_posts() ) {
        $query->the_post();

        $cat_terms = get_the_category();
        $cat_slugs = array();
        if ( ! empty( $cat_terms ) ) {
            foreach ( $cat_terms as $cat ) {
                $cat_slugs[] = $cat->slug;
            }
        }

        $tag_terms = get_the_terms( get_the_ID(), 'post_tag' );
        $tag_slugs = array();
        if ( ! empty( $tag_terms ) && ! is_wp_error( $tag_terms ) ) {
            foreach ( $tag_terms as $tag ) {
                $tag_slugs[] = $tag->slug;
            }
        }

        $tokens = array();
        foreach ( $cat_slugs as $slug ) {
            $tokens[] = 'category:' . $slug;
        }
        foreach ( $tag_slugs as $slug ) {
            $tokens[] = 'post_tag:' . $slug;
        }

        $title     = get_the_title();
        $excerpt   = get_the_excerpt();
        $search    = strtolower( $title . ' ' . wp_strip_all_tags( $excerpt ) );

        $posts[] = array(
            'title'       => $title,
            'title_attr'  => strtolower( $title ),
            'excerpt'     => $excerpt,
            'permalink'   => get_permalink(),
            'thumb'       => get_the_post_thumbnail( get_the_ID(), 'medium' ),
            'terms'       => implode( ' ', $tokens ),
            'date'        => get_the_date( 'U' ),
            'modified'    => get_the_modified_date( 'U' ),
            'index'       => $index,
            'search'      => $search,
        );
        $index++;
    }
    wp_reset_postdata();

    // Config dropdowns
    $dropdowns = get_option( $dropdown_option, array() );
    if ( ! is_array( $dropdowns ) ) {
        $dropdowns = array();
    }

    $has_multiple_posts = count( $posts ) > 1;

    ob_start();
    ?>
    <div class="wp-pfg-wrapper">

        <?php if ( $has_multiple_posts || ! empty( $dropdowns ) ) : ?>
            <div class="wp-pfg-filters">

                <!-- Search input -->
                <label class="wp-pfg-filter-dropdown wp-pfg-search-wrapper">
                    <span class="wp-pfg-filter-label">
                        <?php esc_html_e( 'Search', 'wp-pfg' ); ?>
                    </span>
                    <input
                        type="text"
                        class="wp-pfg-search-input"
                        placeholder="<?php esc_attr_e( 'Search posts…', 'wp-pfg' ); ?>"
                    />
                </label>

                <!-- Sort dropdown -->
                <?php if ( $has_multiple_posts ) : ?>
                    <label class="wp-pfg-filter-dropdown wp-pfg-sort-dropdown">
                        <span class="wp-pfg-filter-label"><?php esc_html_e( 'Sort by', 'wp-pfg' ); ?></span>
                        <select class="wp-pfg-sort-select">
                            <option value="default"><?php esc_html_e( 'Default', 'wp-pfg' ); ?></option>
                            <option value="newest"><?php esc_html_e( 'Newest', 'wp-pfg' ); ?></option>
                            <option value="oldest"><?php esc_html_e( 'Oldest', 'wp-pfg' ); ?></option>
                            <option value="title-asc"><?php esc_html_e( 'Title A–Z', 'wp-pfg' ); ?></option>
                            <option value="title-desc"><?php esc_html_e( 'Title Z–A', 'wp-pfg' ); ?></option>
                        </select>
                    </label>
                <?php endif; ?>

                <!-- Filter dropdowns -->
                <?php if ( ! empty( $dropdowns ) ) : ?>
                    <?php foreach ( $dropdowns as $dropdown ) : ?>
                        <?php
                        if ( empty( $dropdown['label'] ) || empty( $dropdown['terms'] ) ) {
                            continue;
                        }

                        $taxonomy  = isset( $dropdown['taxonomy'] ) ? $dropdown['taxonomy'] : 'category';
                        $term_ids  = is_array( $dropdown['terms'] ) ? $dropdown['terms'] : array();
                        $term_data = array();

                        foreach ( $term_ids as $term_id ) {
                            $term_id = (int) $term_id;
                            if ( $term_id <= 0 ) {
                                continue;
                            }
                            $term = get_term( $term_id );
                            if ( $term && ! is_wp_error( $term ) ) {
                                $token       = $term->taxonomy . ':' . $term->slug;
                                $term_data[] = array(
                                    'name'  => $term->name,
                                    'token' => $token,
                                );
                            }
                        }

                        if ( empty( $term_data ) ) {
                            continue;
                        }
                        ?>
                        <label class="wp-pfg-filter-dropdown">
                            <span class="wp-pfg-filter-label">
                                <?php echo esc_html( $dropdown['label'] ); ?>
                            </span>
                            <select class="wp-pfg-filter-select" data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>">
                                <option value=""><?php esc_html_e( 'All', 'wp-pfg' ); ?></option>
                                <?php foreach ( $term_data as $t ) : ?>
                                    <option value="<?php echo esc_attr( $t['token'] ); ?>">
                                        <?php echo esc_html( $t['name'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>

            </div>
        <?php endif; ?>

        <div class="wp-pfg-grid">
            <div class="wp-pfg-no-results" style="display:none;">
                <?php esc_html_e( 'No results found.', 'wp-pfg' ); ?>
            </div>

            <?php foreach ( $posts as $p ) : ?>
                <article
                    class="wp-pfg-card"
                    data-terms="<?php echo esc_attr( $p['terms'] ); ?>"
                    data-date="<?php echo esc_attr( $p['date'] ); ?>"
                    data-modified="<?php echo esc_attr( $p['modified'] ); ?>"
                    data-title="<?php echo esc_attr( $p['title_attr'] ); ?>"
                    data-index="<?php echo esc_attr( $p['index'] ); ?>"
                    data-search="<?php echo esc_attr( $p['search'] ); ?>"
                >
                    <a href="<?php echo esc_url( $p['permalink'] ); ?>" class="wp-pfg-card-inner">

                        <?php if ( ! empty( $p['thumb'] ) ) : ?>
                            <div class="wp-pfg-card-image">
                                <?php echo $p['thumb']; ?>
                            </div>
                        <?php endif; ?>

                        <div class="wp-pfg-card-content">
                            <h3 class="wp-pfg-card-title">
                                <?php echo esc_html( $p['title'] ); ?>
                            </h3>
                            <p class="wp-pfg-card-excerpt">
                                <?php echo esc_html( wp_trim_words( $p['excerpt'], 20, '…' ) ); ?>
                            </p>
                            <span class="wp-pfg-card-readmore">
                                <?php esc_html_e( 'Read more →', 'wp-pfg' ); ?>
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
