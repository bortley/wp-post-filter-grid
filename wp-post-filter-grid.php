<?php
/**
 * Plugin Name: WP Post Filter Grid
 * Description: Display blog posts in a responsive grid with real-time taxonomy filters, search, sort, CSV export, and multiple configurations via shortcode IDs.
 * Version: 3.9.4
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
        '3.9.4'
    );

    wp_enqueue_script(
        'wp-pfg-script',
        plugin_dir_url( __FILE__ ) . 'assets/js/script.js',
        array(), // vanilla JS only
        '3.9.4',
        true
    );

    // Provide AJAX URL + nonce for full-content search
    wp_localize_script(
        'wp-pfg-script',
        'WP_PFG',
        array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wp_pfg_search_nonce' ),
        )
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

        // Optional: URL parameter key for this dropdown (e.g. type, expertise)
        $param_key = '';
        if ( isset( $dropdown['param_key'] ) ) {
            $param_key = sanitize_key( $dropdown['param_key'] );
        }

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
            'label'     => $label,
            'taxonomy'  => $taxonomy,
            'param_key' => $param_key,
            'terms'     => array_values( array_unique( $terms ) ),
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
 *   ADMIN MENUS (Settings pages)
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
 *   ADMIN MENU (Tools → Export)
 * ===================================
 */
function wp_pfg_add_export_menu() {
    add_management_page(
        'Post Filter Grid Export',
        'Post Filter Grid Export',
        'manage_options',
        'wp-pfg-export',
        'wp_pfg_render_export_page'
    );
}
add_action( 'admin_menu', 'wp_pfg_add_export_menu' );


/**
 * ===================================
 *   DEFAULT CONFIG PAGE
 * ===================================
 */
function wp_pfg_render_default_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Handle delete configuration
    if ( isset( $_POST['wp_pfg_delete_config_id'] ) ) {
        $id = sanitize_key( wp_unslash( $_POST['wp_pfg_delete_config_id'] ) );

        check_admin_referer( 'wp_pfg_delete_config_' . $id, 'wp_pfg_delete_config_nonce' );

        $configs = get_option( 'wp_pfg_config_ids', array() );
        if ( ! is_array( $configs ) ) {
            $configs = array();
        }

        $configs = array_diff( $configs, array( $id ) );
        update_option( 'wp_pfg_config_ids', array_values( $configs ) );

        delete_option( 'wp_pfg_included_categories_' . $id );
        delete_option( 'wp_pfg_filter_dropdowns_' . $id );

        wp_safe_redirect( admin_url( 'options-general.php?page=wp-pfg-default' ) );
        exit;
    }

    // Handle creation of a new configuration
    if ( isset( $_POST['wp_pfg_new_config_id'] ) ) {
        check_admin_referer( 'wp_pfg_add_config', 'wp_pfg_add_config_nonce' );

        $new = sanitize_key( wp_unslash( $_POST['wp_pfg_new_config_id'] ) );
        if ( $new && 'default' !== $new ) {
            $configs = get_option( 'wp_pfg_config_ids', array() );
            if ( ! is_array( $configs ) ) {
                $configs = array();
            }

            $configs[] = $new;
            $configs   = array_values( array_unique( $configs ) );
            update_option( 'wp_pfg_config_ids', $configs );

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

        <!-- Create configuration form -->
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

        <h2 style="margin-top:2em;">Existing Configurations</h2>
        <ul>
            <?php if ( ! empty( $config_ids ) && is_array( $config_ids ) ) : ?>
                <?php foreach ( $config_ids as $id ) : ?>
                    <li style="margin-bottom:8px;">
                        <a href="<?php echo esc_url( admin_url( 'options-general.php?page=wp-pfg-settings-' . $id ) ); ?>">
                            <?php echo esc_html( ucwords( str_replace( array( '-', '_' ), ' ', $id ) ) ); ?>
                        </a>
                        &nbsp;<code>[wp_post_filter_grid id="<?php echo esc_attr( $id ); ?>"]</code>

                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete configuration <?php echo esc_js( $id ); ?>? This cannot be undone.');">
                            <?php wp_nonce_field( 'wp_pfg_delete_config_' . $id, 'wp_pfg_delete_config_nonce' ); ?>
                            <input type="hidden" name="wp_pfg_delete_config_id" value="<?php echo esc_attr( $id ); ?>">
                            <button type="submit" class="button-link-delete" style="color:#b32d2e; margin-left:10px;">
                                <?php esc_html_e( 'Delete', 'wp-pfg' ); ?>
                            </button>
                        </form>
                    </li>
                <?php endforeach; ?>
            <?php else : ?>
                <li><em>No additional configurations created yet.</em></li>
            <?php endif; ?>
        </ul>

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
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
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
                    $label     = isset( $dropdown['label'] ) ? $dropdown['label'] : '';
                    $taxonomy  = isset( $dropdown['taxonomy'] ) ? $dropdown['taxonomy'] : 'category';
                    $param_key = isset( $dropdown['param_key'] ) ? sanitize_key( $dropdown['param_key'] ) : '';
                    $terms     = isset( $dropdown['terms'] ) && is_array( $dropdown['terms'] ) ? $dropdown['terms'] : array();

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
                                URL Key (used in URL, e.g. type, expertise):<br />
                                <input
                                    type="text"
                                    class="regular-text"
                                    name="<?php echo esc_attr( $dropdown_option ); ?>[<?php echo (int) $i; ?>][param_key]"
                                    value="<?php echo esc_attr( isset( $dropdown['param_key'] ) ? $dropdown['param_key'] : '' ); ?>"
                                    placeholder="type"
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

                        <p><strong>Terms (select, then drag to order):</strong></p>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                            <!-- LEFT: Available terms (checkboxes) -->
                            <div>
                                <p style="margin-top:0;"><strong>Available Terms</strong></p>
                                <div style="max-height:220px; overflow-y:auto; border:1px solid #ddd; padding:8px; background:#fff;">
                                    <?php
                                    if ( ! empty( $term_list ) && ! is_wp_error( $term_list ) ) :
                                        foreach ( $term_list as $term ) :
                                            $is_checked = in_array( $term->term_id, $terms, true );
                                            ?>
                                            <label style="display:block; margin-bottom:4px;">
                                                <input
                                                    type="checkbox"
                                                    class="wp-pfg-term-checkbox"
                                                    data-term-id="<?php echo esc_attr( $term->term_id ); ?>"
                                                    data-term-label="<?php echo esc_attr( $term->name ); ?>"
                                                    <?php checked( $is_checked ); ?>
                                                    name="wp_pfg_term_select_dummy_<?php echo esc_attr( $config_id ); ?>[<?php echo (int) $i; ?>][]"
                                                    value="<?php echo esc_attr( $term->term_id ); ?>"
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
                                <p style="margin:8px 0 0; color:#666;">
                                    Tip: Checked terms appear on the right. Drag them to reorder.
                                </p>
                            </div>

                            <!-- RIGHT: Selected order (draggable) -->
                            <div>
                                <p style="margin-top:0;"><strong>Selected Terms Order</strong></p>

                                <ul
                                    class="wp-pfg-selected-terms"
                                    data-option-name="<?php echo esc_attr( $dropdown_option ); ?>"
                                    data-index="<?php echo (int) $i; ?>"
                                    style="margin:0; padding:8px; border:1px solid #ddd; background:#fff; min-height:220px; list-style:none;"
                                >
                                    <?php
                                    // Render selected terms in the saved order
                                    if ( ! empty( $terms ) ) :
                                        foreach ( $terms as $selected_id ) :
                                            $selected_id = (int) $selected_id;
                                            if ( $selected_id <= 0 ) continue;

                                            $selected_term = get_term( $selected_id );
                                            if ( ! $selected_term || is_wp_error( $selected_term ) ) continue;
                                            ?>
                                            <li
                                                class="wp-pfg-selected-term"
                                                draggable="true"
                                                data-term-id="<?php echo esc_attr( $selected_id ); ?>"
                                                style="display:flex; align-items:center; justify-content:space-between; gap:10px; padding:8px; margin-bottom:6px; border:1px solid #eee; cursor:grab; background:#fafafa;"
                                            >
                                                <span><?php echo esc_html( $selected_term->name ); ?></span>
                                                <span style="color:#999;">↕</span>

                                                <!-- Hidden inputs save ordering -->
                                                <input
                                                    type="hidden"
                                                    name="<?php echo esc_attr( $dropdown_option ); ?>[<?php echo (int) $i; ?>][terms][]"
                                                    value="<?php echo esc_attr( $selected_id ); ?>"
                                                />
                                            </li>
                                            <?php
                                        endforeach;
                                    endif;
                                    ?>
                                </ul>

                                <p style="margin:8px 0 0; color:#666;">
                                    Drag items up/down to control dropdown order.
                                </p>
                            </div>
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

        function indicatedNumber(val) {
            var n = parseInt(val || '0', 10);
            return isNaN(n) ? 0 : n;
        }

        function syncHiddenInputs(listEl) {
            if (!listEl) return;

            var optName = listEl.getAttribute('data-option-name');
            var index   = indicatedNumber(listEl.getAttribute('data-index'));

            // Remove existing hidden inputs
            listEl.querySelectorAll('input[type="hidden"]').forEach(function (inp) {
                inp.remove();
            });

            // Re-add hidden inputs in current LI order
            listEl.querySelectorAll('.wp-pfg-selected-term').forEach(function (li) {
                var termId = li.getAttribute('data-term-id');
                if (!termId) return;

                var hidden = document.createElement('input');
                hidden.type  = 'hidden';
                hidden.name  = optName + '[' + index + '][terms][]';
                hidden.value = termId;
                li.appendChild(hidden);
            });
        }

        function addSelectedTerm(listEl, termId, termLabel) {
            if (!listEl || !termId) return;

            if (listEl.querySelector('.wp-pfg-selected-term[data-term-id="' + termId + '"]')) return;

            var li = document.createElement('li');
            li.className = 'wp-pfg-selected-term';
            li.setAttribute('draggable', 'true');
            li.setAttribute('data-term-id', termId);
            li.style.display = 'flex';
            li.style.alignItems = 'center';
            li.style.justifyContent = 'space-between';
            li.style.gap = '10px';
            li.style.padding = '8px';
            li.style.marginBottom = '6px';
            li.style.border = '1px solid #eee';
            li.style.cursor = 'grab';
            li.style.background = '#fafafa';

            var nameSpan = document.createElement('span');
            nameSpan.textContent = termLabel || termId;

            var handleSpan = document.createElement('span');
            handleSpan.textContent = '↕';
            handleSpan.style.color = '#999';

            li.appendChild(nameSpan);
            li.appendChild(handleSpan);

            listEl.appendChild(li);
            syncHiddenInputs(listEl);
        }

        function removeSelectedTerm(listEl, termId) {
            if (!listEl || !termId) return;
            var existing = listEl.querySelector('.wp-pfg-selected-term[data-term-id="' + termId + '"]');
            if (existing) {
                existing.remove();
                syncHiddenInputs(listEl);
            }
        }

        function initDragDrop(listEl) {
            if (!listEl || listEl.dataset.dndInit === '1') return;
            listEl.dataset.dndInit = '1';

            var dragged = null;

            listEl.addEventListener('dragstart', function (e) {
                var li = e.target.closest('.wp-pfg-selected-term');
                if (!li) return;
                dragged = li;
                li.style.opacity = '0.5';
            });

            listEl.addEventListener('dragend', function () {
                if (dragged) dragged.style.opacity = '1';
                dragged = null;
                syncHiddenInputs(listEl);
            });

            listEl.addEventListener('dragover', function (e) {
                e.preventDefault();
                var over = e.target.closest('.wp-pfg-selected-term');
                if (!over || !dragged || over === dragged) return;

                var rect = over.getBoundingClientRect();
                var isAfter = (e.clientY - rect.top) > (rect.height / 2);

                if (isAfter) over.after(dragged);
                else over.before(dragged);
            });

            listEl.addEventListener('drop', function (e) {
                e.preventDefault();
                syncHiddenInputs(listEl);
            });
        }

        function initBlock(blockEl) {
            if (!blockEl) return;

            var listEl = blockEl.querySelector('.wp-pfg-selected-terms');
            if (!listEl) return;

            syncHiddenInputs(listEl);
            initDragDrop(listEl);

            blockEl.querySelectorAll('.wp-pfg-term-checkbox:checked').forEach(function (cb) {
                var termId = cb.getAttribute('data-term-id');
                var label  = cb.getAttribute('data-term-label') || cb.parentElement.textContent.trim();
                addSelectedTerm(listEl, termId, label);
            });

            blockEl.querySelectorAll('.wp-pfg-term-checkbox:not(:checked)').forEach(function (cb) {
                var termId = cb.getAttribute('data-term-id');
                removeSelectedTerm(listEl, termId);
            });
        }

        // Checkbox change -> add/remove from order list
        container.addEventListener('change', function (e) {
            var cb = e.target;
            if (!cb.classList || !cb.classList.contains('wp-pfg-term-checkbox')) return;

            var blockEl = cb.closest('.wp-pfg-dropdown-block');
            if (!blockEl) return;

            var listEl = blockEl.querySelector('.wp-pfg-selected-terms');
            if (!listEl) return;

            var termId = cb.getAttribute('data-term-id');
            var label  = cb.getAttribute('data-term-label') || cb.parentElement.textContent.trim();

            if (cb.checked) addSelectedTerm(listEl, termId, label);
            else removeSelectedTerm(listEl, termId);
        });

        // Add dropdown block (empty terms until save)
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
                + '    <label>URL Key (used in URL, e.g. type, expertise):<br />'
                + '      <input type="text" class="regular-text" name="' + optionName + '[' + index + '][param_key]" placeholder="type" />'
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
                + '  <p><strong>Terms (select, then drag to order):</strong></p>'
                + '  <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 16px;">'
                + '    <div>'
                + '      <p style="margin-top:0;"><strong>Available Terms</strong></p>'
                + '      <div style="max-height:220px; overflow-y:auto; border:1px solid #ddd; padding:8px; background:#fff;">'
                + '        <em>Save to load terms for the selected taxonomy.</em>'
                + '      </div>'
                + '    </div>'
                + '    <div>'
                + '      <p style="margin-top:0;"><strong>Selected Terms Order</strong></p>'
                + '      <ul class="wp-pfg-selected-terms" data-option-name="' + optionName + '" data-index="' + index + '"'
                + '          style="margin:0; padding:8px; border:1px solid #ddd; background:#fff; min-height:220px; list-style:none;"></ul>'
                + '      <p style="margin:8px 0 0; color:#666;">Drag items up/down to control dropdown order.</p>'
                + '    </div>'
                + '  </div>'
                + '  <p><button type="button" class="button wp-pfg-remove-dropdown">Remove</button></p>'
                + '</div>';

            container.insertAdjacentHTML('beforeend', html);

            var newBlock = container.querySelectorAll('.wp-pfg-dropdown-block')[index];
            if (newBlock) initBlock(newBlock);
        });

        // Remove dropdown block
        container.addEventListener('click', function (e) {
            if (e.target.classList.contains('wp-pfg-remove-dropdown')) {
                var block = e.target.closest('.wp-pfg-dropdown-block');
                if (block) block.remove();
            }
        });

        // Initialize existing blocks
        container.querySelectorAll('.wp-pfg-dropdown-block').forEach(initBlock);
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

    if ( $config_id ) {
        $included_option = 'wp_pfg_included_categories_' . $config_id;
        $dropdown_option = 'wp_pfg_filter_dropdowns_' . $config_id;
    } else {
        $included_option = 'wp_pfg_included_categories';
        $dropdown_option = 'wp_pfg_filter_dropdowns';
    }

    $config_included = get_option( $included_option, array() );
    if ( ! is_array( $config_included ) ) {
        $config_included = array();
    }

    $shortcode_included = wp_pfg_get_category_ids_from_slugs( $atts['include_cats'] );

    $all_included = array_unique(
        array_filter(
            array_map(
                'intval',
                array_merge( $config_included, $shortcode_included )
            )
        )
    );

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

        $title   = get_the_title();
        $excerpt = get_the_excerpt();
        $search  = strtolower( $title . ' ' . wp_strip_all_tags( $excerpt ) ); // fallback only

        $posts[] = array(
            'ID'          => get_the_ID(),
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

    $dropdowns = get_option( $dropdown_option, array() );
    if ( ! is_array( $dropdowns ) ) {
        $dropdowns = array();
    }

    $has_multiple_posts = count( $posts ) > 1;

    ob_start();
    ?>
    <div class="wp-pfg-wrapper" data-include-cats="<?php echo esc_attr( implode( ',', $all_included ) ); ?>">

        <?php if ( $has_multiple_posts || ! empty( $dropdowns ) ) : ?>
    <?php $filters_id = 'wp-pfg-filters-' . wp_unique_id(); ?>

    <button
        type="button"
        class="wp-pfg-filters-toggle"
        aria-expanded="false"
        aria-controls="<?php echo esc_attr( $filters_id ); ?>"
    >
        <?php esc_html_e( 'Show Filters', 'wp-pfg' ); ?>
    </button>

    <div class="wp-pfg-filters" id="<?php echo esc_attr( $filters_id ); ?>">


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

                <!-- Taxonomy filter dropdowns -->
                <?php if ( ! empty( $dropdowns ) ) : ?>
                    <?php foreach ( $dropdowns as $idx => $dropdown ) : ?>
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
                                    'slug'  => $term->slug,
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
                            <select class="wp-pfg-filter-select"
                                data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"
                                data-param-key="<?php echo esc_attr( ! empty( $dropdown['param_key'] ) ? $dropdown['param_key'] : sanitize_key( $dropdown['label'] ) ); ?>">
                                <option value=""><?php esc_html_e( 'All', 'wp-pfg' ); ?></option>
                                <?php foreach ( $term_data as $t ) : ?>
                                    <option value="<?php echo esc_attr( $t['token'] ); ?>" data-slug="<?php echo esc_attr( $t['slug'] ); ?>">
                                        <?php echo esc_html( $t['name'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Clear Filters button at the end -->
                <button type="button" class="wp-pfg-clear-filters button">
                    <?php esc_html_e( 'Clear Filters', 'wp-pfg' ); ?>
                </button>

            </div>
        <?php endif; ?>

        <div class="wp-pfg-grid">
            <div class="wp-pfg-no-results" style="display:none;">
                <?php esc_html_e( 'No results found.', 'wp-pfg' ); ?>
            </div>

            <?php foreach ( $posts as $p ) : ?>
                <article
                    class="wp-pfg-card"
                    data-post-id="<?php echo esc_attr( $p['ID'] ); ?>"
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
                            <div class="wp-pfg-card-title">
                                <?php echo esc_html( $p['title'] ); ?>
                            </div>
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


/**
 * ===================================
 *   CSV EXPORT HANDLER
 *   URL: /wp-admin/admin-post.php?action=wp_pfg_export_csv
 * ===================================
 */
function wp_pfg_handle_export_csv() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to export posts.' );
    }

    if ( ob_get_length() ) {
        ob_end_clean();
    }

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=posts-export-' . gmdate( 'Y-m-d' ) . '.csv' );

    $output = fopen( 'php://output', 'w' );
    fputcsv( $output, array( 'Post Title', 'Publish Date', 'Categories', 'Tags' ) );

    $posts = get_posts( array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );

    foreach ( $posts as $post ) {
        $post_id = $post->ID;

        $date = get_the_date( 'Y-m-d', $post_id );

        $cat_terms = get_the_terms( $post_id, 'category' );
        $cat_names = array();
        if ( ! empty( $cat_terms ) && ! is_wp_error( $cat_terms ) ) {
            foreach ( $cat_terms as $t ) {
                $cat_names[] = $t->name;
            }
        }
        $categories_str = implode( ', ', $cat_names );

        $tag_terms = get_the_terms( $post_id, 'post_tag' );
        $tag_names = array();
        if ( ! empty( $tag_terms ) && ! is_wp_error( $tag_terms ) ) {
            foreach ( $tag_terms as $t ) {
                $tag_names[] = $t->name;
            }
        }
        $tags_str = implode( ', ', $tag_names );

        fputcsv( $output, array(
            $post->post_title,
            $date,
            $categories_str,
            $tags_str,
        ) );
    }

    fclose( $output );
    exit;
}
add_action( 'admin_post_wp_pfg_export_csv', 'wp_pfg_handle_export_csv' );


/**
 * ===================================
 *   CSV EXPORT PAGE (Tools → Post Filter Grid Export)
 * ===================================
 */
function wp_pfg_render_export_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $export_url = admin_url( 'admin-post.php?action=wp_pfg_export_csv' );
    ?>
    <div class="wrap">
        <h1>Post Filter Grid — CSV Export</h1>
        <p>
            Click the button below to download a CSV file containing one row per post
            with the following columns:
        </p>
        <ul style="list-style:disc; margin-left:20px;">
            <li>Post Title</li>
            <li>Publish Date (Y-m-d)</li>
            <li>Categories (comma-separated)</li>
            <li>Tags (comma-separated)</li>
        </ul>

        <p>
            <a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary">
                Download Posts CSV
            </a>
        </p>
    </div>
    <?php
}


/**
 * ===================================
 *   AJAX: FULL-CONTENT SEARCH
 *   Returns matching post IDs for a query (searches title/content/excerpt).
 * ===================================
 */
function wp_pfg_ajax_fulltext_search() {

    // Nonce verification for logged-in users only (avoids cached/stale nonces for guests)
    if ( is_user_logged_in() ) {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'wp_pfg_search_nonce' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid nonce.' ), 403 );
        }
    }

    $q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
    $q = trim( $q );

    // Empty/too short query => no constraint
    if ( $q === '' || strlen( $q ) < 2 ) {
        wp_send_json_success( array( 'ids' => array() ) );
    }

    // Optional: constrain search to included categories
    $include_cats = array();
    if ( isset( $_POST['include_cats'] ) ) {
        if ( is_array( $_POST['include_cats'] ) ) {
            $include_cats = array_map( 'intval', wp_unslash( $_POST['include_cats'] ) );
        } else {
            $raw          = sanitize_text_field( wp_unslash( $_POST['include_cats'] ) );
            $include_cats = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
        }
        $include_cats = array_values( array_filter( $include_cats ) );
    }

    // PASS 1: Fast SQL search for candidates
    $args = array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        's'              => $q,
        'posts_per_page' => 500, // candidate cap
        'fields'         => 'ids',
        'no_found_rows'  => true,
    );

    if ( ! empty( $include_cats ) ) {
        $args['category__in'] = $include_cats;
    }

    $ids = get_posts( $args );
    if ( ! is_array( $ids ) ) {
        $ids = array();
    }

    // PASS 2: Match against cleaned, human-visible text (substring match)
    $filtered_ids = array();
    $q_lower      = function_exists( 'mb_strtolower' ) ? mb_strtolower( $q ) : strtolower( $q );

    foreach ( $ids as $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            continue;
        }

        $title   = (string) $post->post_title;
        $excerpt = (string) $post->post_excerpt;
        $content = (string) $post->post_content;

        // Render blocks + shortcodes so visible text is included
        if ( function_exists( 'do_blocks' ) ) {
            $content = do_blocks( $content );
        }
        $content = do_shortcode( $content );

        // Remove script/style blocks (common false-positive source)
        $content = preg_replace( '#<script\b[^>]*>.*?</script>#is', ' ', $content );
        $content = preg_replace( '#<style\b[^>]*>.*?</style>#is', ' ', $content );

        // Strip remaining HTML tags
        $content = wp_strip_all_tags( $content );

        $haystack = $title . ' ' . $excerpt . ' ' . $content;
        $haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : strtolower( $haystack );

        if ( $q_lower !== '' && strpos( $haystack, $q_lower ) !== false ) {
            $filtered_ids[] = (int) $post_id;
        }
    }

    wp_send_json_success( array( 'ids' => $filtered_ids ) );
}

add_action( 'wp_ajax_wp_pfg_fulltext_search', 'wp_pfg_ajax_fulltext_search' );
add_action( 'wp_ajax_nopriv_wp_pfg_fulltext_search', 'wp_pfg_ajax_fulltext_search' );
