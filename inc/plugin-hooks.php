<?php
defined( 'ABSPATH' ) || exit;

/**
 * Enqueues frontend assets and passes config to JS as CF_Data:
 *   autosubmit      {bool}   — submit form on every input change
 *   shop_url        {string} — form action + "clear all" href
 *   tax_labels      {object} — { cf_param_key: "Label" } for the active-filters bar
 *   currency_symbol {string} — decoded WC symbol, e.g. "€"
 *   currency_pos    {string} — left | right | left_space | right_space
 */
add_action( 'wp_enqueue_scripts', 'cf_enqueue_frontend_assets' );
function cf_enqueue_frontend_assets() {
	wp_enqueue_style(  'cf-filter-style',  CF_URL . 'assets/css/filter.css', [], CF_VERSION );
	wp_enqueue_script( 'cf-filter-script', CF_URL . 'assets/js/filter.js', [], CF_VERSION, true );

	$settings = get_option( 'cf_general_settings', [] );
	$filters  = get_option( 'cf_filters', [] );

	$tax_labels = [];
	foreach ( $filters as $f ) {
		if ( ! empty( $f['taxonomy'] ) && $f['taxonomy'] !== '_price' ) {
			$tax_labels[ cf_param_key( $f['taxonomy'], $f['url_key'] ?? '' ) ] =
				! empty( $f['label_text'] ) ? $f['label_text'] : cf_get_taxonomy_label( $f['taxonomy'] );
		}
	}

	wp_localize_script( 'cf-filter-script', 'CF_Data', [
		'autosubmit'      => ! empty( $settings['autosubmit'] ),
		'ajax_filter'     => ! empty( $settings['ajax_filter'] ),
		'ajax_url'        => admin_url( 'admin-ajax.php' ),
		'request_url'     => home_url( add_query_arg( [] ) ), // current full URL incl. rewrites
		'shop_url'        => function_exists( 'wc_get_page_id' ) ? get_permalink( wc_get_page_id( 'shop' ) ) : home_url( '/' ),
		'tax_labels'      => $tax_labels,
        'currency_symbol' => ( isset( $settings['price_currency'] ) && $settings['price_currency'] !== '' )
            ? $settings['price_currency']
            : ( function_exists( 'get_woocommerce_currency_symbol' )
                ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' )
                : '' ),
        'currency_pos'    => 'left',
		'nonce'           => wp_create_nonce( 'cf_ajax_filter' ),
	] );
}


add_action( 'admin_enqueue_scripts', 'cf_enqueue_admin_assets' );
function cf_enqueue_admin_assets( $hook ) {
	if ( strpos( $hook, 'cf-settings' ) === false ) return;
	wp_enqueue_style(  'cf-admin-style',  CF_URL . 'assets/css/admin.css', [], CF_VERSION );
	wp_enqueue_script( 'cf-admin-script', CF_URL . 'assets/js/admin.js', [ 'jquery', 'jquery-ui-sortable' ], CF_VERSION, true );
	wp_localize_script( 'cf-admin-script', 'CF_Admin', [
		'ajax_url' => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'cf_admin_nonce' ),
	] );
}

require_once CF_PATH . 'inc/filter-render.php';

add_shortcode( 'custom_filter',  'cf_render_filter_shortcode' );
add_shortcode( 'active_filters', 'cf_render_active_filters_shortcode' );

function cf_render_filter_shortcode() {
	ob_start(); cf_render_filter_output(); return ob_get_clean();
}
function cf_render_active_filters_shortcode() {
	$shop_url = function_exists( 'wc_get_page_id' ) ? get_permalink( wc_get_page_id( 'shop' ) ) : home_url( '/' );
    if ( is_tax() || is_product_category() || is_product_tag() ) {
        $shop_url = get_term_link( get_queried_object() );
    }
	ob_start(); cf_render_active_filters_bar( get_option( 'cf_filters', [] ), $shop_url ); return ob_get_clean();
}

/**
 * Applies taxonomy + price filters to the WP query.
 *
 * Two hooks are registered because pre_get_posts fires before WC finishes
 * setting up its loop context, while woocommerce_product_query is WC-specific
 * and catches widget/block loops that pre_get_posts misses.
 *
 * Use $q->is_*() here — NOT global is_*() — global conditional tags are not
 * reliable this early in the request lifecycle.
 */
add_action( 'pre_get_posts',           'cf_pre_get_posts_filter' );
add_action( 'woocommerce_product_query', 'cf_wc_product_query_filter' );

function cf_pre_get_posts_filter( $q ) {
	if ( is_admin() || ! $q->is_main_query() ) return;
	if ( ! $q->is_post_type_archive( 'product' ) && ! $q->is_tax() ) return;
	cf_apply_all_filters( $q );
}
function cf_wc_product_query_filter( $q ) {
	cf_apply_all_filters( $q );
}

function cf_apply_all_filters( $q ) {
	$tax   = cf_build_tax_query_from_request( $_GET );
	$price = cf_build_price_query_from_request( $_GET );

	if ( ! empty( $tax ) )
		$q->set( 'tax_query',  array_merge( (array) $q->get( 'tax_query' ),  $tax ) );
	if ( ! empty( $price ) )
		$q->set( 'meta_query', array_merge( (array) $q->get( 'meta_query' ), [ $price ] ) );
}

/**
 * Converts a taxonomy slug to its URL param key.
 *
 * Always prefixed "cf_" — never "filter_" — because WC's QueryClauses hook
 * calls trim() on filter_* params expecting strings; arrays cause a fatal.
 *
 * $url_key is the optional short key set per-filter in the admin (e.g. "q").
 * Without it, WC's "pa_" attribute prefix is stripped: pa_color → cf_color.
 *
 * To add a new filter type that needs its own param naming, extend this function
 * or pass a custom $url_key from your filter config.
 */
function cf_param_key( $taxonomy, $url_key = '' ) {
	if ( $url_key !== '' ) {
		return 'cf_' . $url_key;
	}
	// Strip known WC prefixes so product_cat→cf_cat, pa_color→cf_color.
	return 'cf_' . preg_replace( '/^(pa_|product_)/', '', $taxonomy );
}

/**
 * Builds the tax_query array from active cf_* GET params.
 *
 * Logic per filter:
 *   or     → operator IN  (match any selected term)
 *   and    → operator AND (must match all selected terms)
 *   single → operator IN  (JS limits UI to one value; SQL same as OR)
 *
 * To support a new taxonomy filter type, ensure it stores 'taxonomy',
 * 'url_key', and 'logic' keys in the cf_filters option array.
 */
function cf_build_tax_query_from_request( $params = [] ) {
	$tax_query      = [];
	$handled_keys   = [];

	// ── Configured filters (use their stored url_key and logic) ──
	foreach ( get_option( 'cf_filters', [] ) as $filter ) {
		if ( empty( $filter['taxonomy'] ) || $filter['taxonomy'] === '_price' ) continue;
		$key   = cf_param_key( $filter['taxonomy'], $filter['url_key'] ?? '' );
		$slugs = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $params[ $key ] ?? [] ) ) ) );
		$handled_keys[] = $key;
		if ( empty( $slugs ) ) continue;
		$tax_query[] = [
			'taxonomy' => $filter['taxonomy'],
			'field'    => 'slug',
			'terms'    => $slugs,
			'operator' => ( ( $filter['logic'] ?? 'or' ) === 'and' ) ? 'AND' : 'IN',
		];
	}

	// ── Fallback: unregistered cf_* params (no url_key, default OR logic) ──
	// Allows taxonomy filters to work even when not added to the Taxonomies tab
	// (e.g. links on product meta, taxonomy-redirect params).
	// Builds a reverse map: cf_param_key(taxonomy) → taxonomy slug.
	$tax_param_map = [];
	foreach ( get_object_taxonomies( 'product', 'names' ) as $tax ) {
		$tax_param_map[ cf_param_key( $tax ) ] = $tax;
	}

	foreach ( $params as $key => $value ) {
		if ( strpos( $key, 'cf_' ) !== 0 ) continue;
		if ( in_array( $key, $handled_keys, true ) ) continue;
		if ( ! isset( $tax_param_map[ $key ] ) ) continue;

		$slugs = array_values( array_filter( array_map( 'sanitize_text_field', (array) $value ) ) );
		if ( empty( $slugs ) ) continue;

		$tax_query[] = [
			'taxonomy' => $tax_param_map[ $key ],
			'field'    => 'slug',
			'terms'    => $slugs,
			'operator' => 'IN',
		];
	}

	return $tax_query;
}

/**
 * Builds a single meta_query clause from active price GET params.
 *
 * Param names: cf_{url_key}_min / cf_{url_key}_max
 * Default (no url_key): cf_price_min / cf_price_max
 *
 * Returns one clause (not an array) for merging into meta_query.
 * If only min or only max is set, uses >= or <= instead of BETWEEN.
 */
function cf_build_price_query_from_request( $params = [] ) {
	foreach ( get_option( 'cf_filters', [] ) as $filter ) {
		if ( ( $filter['taxonomy'] ?? '' ) !== '_price' ) continue;
		$base    = 'cf_' . ( $filter['url_key'] ?: 'price' );
		// Dropdown price range: param is "{base}_range" = "min-max" string.
		if ( isset( $params[ $base . '_range' ] ) && $params[ $base . '_range' ] !== '' ) {
			$parts = explode( '-', sanitize_text_field( $params[ $base . '_range' ] ), 2 );
			if ( count( $parts ) === 2 && is_numeric( $parts[0] ) && is_numeric( $parts[1] ) ) {
				return [ 'key' => '_price', 'type' => 'NUMERIC', 'compare' => 'BETWEEN', 'value' => [ floatval( $parts[0] ), floatval( $parts[1] ) ] ];
			}
		}
		$has_min = isset( $params[ $base . '_min' ] ) && $params[ $base . '_min' ] !== '';
		$has_max = isset( $params[ $base . '_max' ] ) && $params[ $base . '_max' ] !== '';
		if ( ! $has_min && ! $has_max ) continue;

		$clause = [ 'key' => '_price', 'type' => 'NUMERIC' ];
		if ( $has_min && $has_max ) {
			$clause += [ 'compare' => 'BETWEEN', 'value' => [ floatval( $params[ $base . '_min' ] ), floatval( $params[ $base . '_max' ] ) ] ];
		} elseif ( $has_min ) {
			$clause += [ 'compare' => '>=', 'value' => floatval( $params[ $base . '_min' ] ) ];
		} else {
			$clause += [ 'compare' => '<=', 'value' => floatval( $params[ $base . '_max' ] ) ];
		}
		return $clause;
	}
	return [];
}

/**
 * AJAX handler — returns product loop HTML for the current filter state.
 *
 * Accepts the same GET params as the normal form submission plus:
 *   cf_request_url  — the full URL of the shop/archive page (preserves rewrites).
 *   _cf_nonce       — wp_nonce for cf_ajax_filter.
 *
 * Strategy: parse cf_request_url to detect the WP post/term being viewed,
 * then run a fresh WP_Query with the filter params applied.
 * This keeps it universal — works on /shop/, category pages, custom endpoints.
 */
add_action( 'wp_ajax_cf_ajax_filter',        'cf_handle_ajax_filter' );
add_action( 'wp_ajax_nopriv_cf_ajax_filter', 'cf_handle_ajax_filter' );

function cf_handle_ajax_filter() {
    // Verify nonce.
    if ( ! isset( $_POST['_cf_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cf_nonce'] ) ), 'cf_ajax_filter' ) ) {
        wp_send_json_error( [ 'message' => 'Bad nonce' ], 403 );
    }

    // Merge the filter params sent from the form into $_GET so that
    // cf_build_tax_query_from_request() and cf_build_price_query_from_request()
    // pick them up exactly as in a normal page load.
    $filter_params = [];
    if ( ! empty( $_POST['filter_params'] ) ) {
        // filter_params is a URL-encoded query string, e.g. "cf_color[]=red&cf_price_min=10"
        wp_parse_str( sanitize_text_field( wp_unslash( $_POST['filter_params'] ) ), $filter_params );
    }

    // Sanitize every value recursively.
    array_walk_recursive( $filter_params, function ( &$v ) {
        $v = sanitize_text_field( $v );
    } );

    // Determine paged.
    $paged = isset( $filter_params['paged'] ) ? absint( $filter_params['paged'] ) : 1;

    // --- Detect context from the requesting page URL ---
    // This makes the query work on rewrites like /shop/category/tops/ as well
    // as plain ?post_type=product pages.
    $request_url = isset( $_POST['request_url'] ) ? esc_url_raw( wp_unslash( $_POST['request_url'] ) ) : '';
    $queried_tax = null;
    $queried_term = null;

    if ( $request_url ) {
        // Strip host/scheme, keep path+query so url_to_postid / get_term_by work.
        $path = parse_url( $request_url, PHP_URL_PATH );

        // Try to detect a product category / tag / attribute term from the URL.
        // We check every taxonomy registered to 'product'.
        $product_taxonomies = get_object_taxonomies( 'product', 'names' );
        foreach ( $product_taxonomies as $tax ) {
            $tax_obj = get_taxonomy( $tax );
            if ( ! $tax_obj || empty( $tax_obj->rewrite['slug'] ) ) continue;
            $slug_base = trim( $tax_obj->rewrite['slug'], '/' );
            // Match /{slug_base}/{term-slug}[/] pattern anywhere in path.
            if ( preg_match( '#/' . preg_quote( $slug_base, '#' ) . '/([^/]+)#', $path, $m ) ) {
                $term = get_term_by( 'slug', $m[1], $tax );
                if ( $term && ! is_wp_error( $term ) ) {
                    $queried_tax  = $tax;
                    $queried_term = $term;
                    break;
                }
            }
        }
    }

    // --- Build WP_Query args ---
    $per_page = (int) get_option( 'posts_per_page', 12 );
    if ( function_exists( 'wc_get_default_products_per_row' ) ) {
        // Respect WC catalog settings if available.
        $per_page = (int) get_option( 'woocommerce_catalog_columns', 4 )
                  * (int) get_option( 'woocommerce_catalog_rows', 4 );
    }

    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
    ];

    // If we detected a taxonomy term context, restrict to that term.
    if ( $queried_tax && $queried_term ) {
        $args['tax_query'] = [
            [
                'taxonomy' => $queried_tax,
                'field'    => 'term_id',
                'terms'    => $queried_term->term_id,
            ],
        ];
    }

    // Apply filter params (taxonomy + price).
    $tax_filter   = cf_build_tax_query_from_request( $filter_params );
    $price_filter = cf_build_price_query_from_request( $filter_params );

    if ( ! empty( $tax_filter ) ) {
        $existing = $args['tax_query'] ?? [];
        if ( $queried_tax && count( $existing ) ) {
            // Combine context term + filter terms with AND relation.
            $args['tax_query'] = array_merge( [ 'relation' => 'AND' ], $existing, $tax_filter );
        } else {
            $args['tax_query'] = $tax_filter;
        }
    }

    if ( ! empty( $price_filter ) ) {
        $args['meta_query'] = [ $price_filter ];
    }

    // Allow themes/plugins to modify the AJAX query.
    $args = apply_filters( 'cf_ajax_query_args', $args, $filter_params );

    $query = new WP_Query( $args );

    ob_start();

    if ( $query->have_posts() ) {
        // Use WooCommerce's own loop template so theme overrides are respected.
        woocommerce_product_loop_start();
        while ( $query->have_posts() ) {
            $query->the_post();
            wc_get_template_part( 'content', 'product' );
        }
        woocommerce_product_loop_end();
    } else {
        wc_get_template( 'loop/no-products-found.php' );
    }

    $products_html = ob_get_clean();
    wp_reset_postdata();

    // --- Pagination ---
    ob_start();
    $total_pages = $query->max_num_pages;
    if ( $total_pages > 1 ) {
        // Build a pagination URL base from request_url (preserves rewrites).
        $base_url = $request_url ?: CF_Data_shop_url();
        echo paginate_links( [
            'base'      => trailingslashit( strtok( $base_url, '?' ) ) . '%_%',
            'format'    => '?paged=%#%',
            'current'   => $paged,
            'total'     => $total_pages,
            'type'      => 'plain',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
        ] );
    }
    $pagination_html = ob_get_clean();

    wp_send_json_success( [
        'products'   => $products_html,
        'pagination' => $pagination_html,
        'count'      => $query->found_posts,
    ] );
}

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * TAXONOMY → FILTER URL REDIRECT
 *
 * When "Rewrite taxonomy URLs" is enabled, any request to a WC taxonomy
 * archive page (product_cat, product_tag, pa_* attributes) is 301-redirected
 * to the shop page with the matching cf_* filter parameter applied.
 *
 * Example:
 *   /product-category/point/  →  /shop/?cf_category[]=point
 *   /product-tag/sale/        →  /shop/?cf_tag[]=sale
 *   /pa_color/red/            →  /shop/?cf_color[]=red   (if pa_color mapped)
 *
 * Mapped filters (those configured in the plugin's Taxonomies tab) use their
 * configured url_key. Unmapped WC taxonomies fall back to the generic
 * cf_param_key() naming so they still work even without an explicit mapping.
 *
 * Multiple query-string params from the original URL (e.g. pagination or
 * extra filters) are preserved and forwarded to the redirect destination.
 *
 * Uses template_redirect (priority 1) — fires before any template is loaded
 * so no output is sent. wp_safe_redirect() is used with a 301 to be SEO-safe.
 * ─────────────────────────────────────────────────────────────────────────────
 */
add_action( 'template_redirect', 'cf_maybe_redirect_taxonomy_to_filter', 1 );

function cf_maybe_redirect_taxonomy_to_filter() {
    // Feature gate — check the option first to bail out cheaply.
    $settings = get_option( 'cf_general_settings', [] );
    if ( empty( $settings['rewrite_taxonomy_urls'] ) ) {
        return;
    }

    // Only act on taxonomy archives for WooCommerce product taxonomies.
    if ( ! is_tax() ) {
        return;
    }

    $queried_object = get_queried_object();
    if ( ! ( $queried_object instanceof WP_Term ) ) {
        return;
    }

    $taxonomy = $queried_object->taxonomy;
    $term_slug = $queried_object->slug;

    // Confirm this taxonomy belongs to 'product' post type.
    $product_taxonomies = get_object_taxonomies( 'product', 'names' );
    if ( ! in_array( $taxonomy, $product_taxonomies, true ) ) {
        return;
    }

    // Build the cf_ param key for this taxonomy.
    // Priority: use the url_key from a configured filter if one exists.
    // This is the only reliable way — cf_param_key() only strips "pa_", not
    // "product_" (e.g. product_cat → cf_product_cat, which is wrong).
    $param_key = null;
    foreach ( get_option( 'cf_filters', [] ) as $filter ) {
        if ( ( $filter['taxonomy'] ?? '' ) === $taxonomy ) {
            $param_key = cf_param_key( $taxonomy, $filter['url_key'] ?? '' );
            break;
        }
    }
    // Fallback for unmapped taxonomies: strip known WC prefixes (pa_, product_).
    if ( $param_key === null ) {
        $short = preg_replace( '/^(pa_|product_)/', '', $taxonomy );
        $param_key = 'cf_' . $short;
    }

    // Build destination: shop URL + filter param + any extra query args from
    // the original request (e.g. ?paged=2 or other active filters).
    $shop_url = function_exists( 'wc_get_page_id' )
        ? get_permalink( wc_get_page_id( 'shop' ) )
        : home_url( '/' );

    // Carry through any existing GET params; strip WP's internal taxonomy vars.
    $wc_tax_vars  = [ 'product_cat', 'product_tag', $taxonomy, 'taxonomy', 'term' ];
    $extra_params = array_diff_key( $_GET, array_flip( $wc_tax_vars ) );

    // Sanitize scalar params only — array params (other active filters) are
    // kept as-is so their structure survives the add_query_arg() call.
    foreach ( $extra_params as $k => &$v ) {
        if ( is_array( $v ) ) {
            $v = array_map( 'sanitize_text_field', $v );
        } else {
            $v = sanitize_text_field( $v );
        }
    }
    unset( $v );

    // Append the term slug as an array value — matches how cf_* filter params
    // are expected: cf_category[]=tops (not cf_category=tops).
    $extra_params[ $param_key ] = [ sanitize_text_field( $term_slug ) ];

    $redirect_url = add_query_arg( $extra_params, trailingslashit( $shop_url ) );

    wp_safe_redirect( $redirect_url, 301 );
    exit;
}