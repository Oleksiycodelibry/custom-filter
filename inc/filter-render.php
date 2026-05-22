<?php
defined( 'ABSPATH' ) || exit;

/**
 * Returns the dropdown arrow SVG.
 * Customisable in Settings → "Dropdown arrow icon".
 * Uses currentColor so it inherits color from .cf-filter__label-arrow in CSS.
 */
function cf_dropdown_arrow() {
	$svg = get_option( 'cf_general_settings', [] )['dropdown_arrow_svg'] ?? '';
	return $svg ?: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M2 4L6 8L10 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
}

/**
 * Groups a flat list of terms into a parent→children tree.
 * Returns [ 'roots' => WP_Term[], 'by_id' => [ id => [ 'term', 'children' ] ] ]
 * Top-level terms (parent=0 or parent not in list) appear at roots.
 */
function cf_build_term_tree( array $terms ) : array {
	$by_id = [];
	$roots = [];

	foreach ( $terms as $term ) {
		$by_id[ $term->term_id ] = [ 'term' => $term, 'children' => [] ];
	}
	foreach ( $by_id as $id => &$node ) {
		$parent = $node['term']->parent;
		if ( $parent && isset( $by_id[ $parent ] ) ) {
			$by_id[ $parent ]['children'][] = $node['term'];
		} else {
			$roots[] = $node['term'];
		}
	}
	unset( $node );

	return [ 'roots' => $roots, 'by_id' => $by_id ];
}

/**
 * Renders one <li> for a term, recursing into children if any exist.
 */
function cf_render_term_item( WP_Term $term, array $by_id, array $args ) : void {
	$uid          = 'cf-' . $args['taxonomy'] . '-' . $term->term_id;
	$checked      = in_array( $term->slug, $args['selected_vals'], true );
	$list_style   = $args['list_style'];
	$param_name   = $args['param_name'];
	$show_count   = $args['show_count'];
	$children     = $by_id[ $term->term_id ]['children'] ?? [];
	$has_children = ! empty( $children );

	// Open sublist on load if any direct child is already selected
	$descendant_checked = false;
	if ( $has_children ) {
		foreach ( $children as $child ) {
			if ( in_array( $child->slug, $args['selected_vals'], true ) ) {
				$descendant_checked = true;
				break;
			}
		}
	}

	$li_class = 'cf-filter__item';
	if ( $has_children ) $li_class .= ' cf-filter__item--has-children';
	if ( $descendant_checked ) $li_class .= ' cf-filter__item--open';
	?>
	<li class="<?php echo esc_attr( $li_class ); ?>">
		<div class="cf-filter__item-row">
			<?php if ( $list_style === 'label' ) : ?>
				<label class="cf-filter__label-item<?php echo $checked ? ' is-active' : ''; ?>" for="<?php echo esc_attr( $uid ); ?>">
					<input type="checkbox" id="<?php echo esc_attr( $uid ); ?>" name="<?php echo esc_attr( $param_name ); ?>" value="<?php echo esc_attr( $term->slug ); ?>" class="cf-filter__input cf-sr-only" <?php checked( $checked ); ?>>
					<span class="cf-filter__item-name"><?php echo esc_html( $term->name ); ?></span>
					<?php if ( $show_count ) : ?><span class="cf-filter__count"><?php echo absint( $term->count ); ?></span><?php endif; ?>
				</label>

			<?php elseif ( $list_style === 'radio' ) : ?>
				<label class="cf-filter__radio-label<?php echo $checked ? ' is-checked' : ''; ?>" for="<?php echo esc_attr( $uid ); ?>">
					<span class="cf-filter__radio-dot" aria-hidden="true"></span>
					<input type="checkbox" id="<?php echo esc_attr( $uid ); ?>" name="<?php echo esc_attr( $param_name ); ?>" value="<?php echo esc_attr( $term->slug ); ?>" class="cf-filter__input cf-sr-only cf-radio-visual" <?php checked( $checked ); ?>>
					<span class="cf-filter__item-name"><?php echo esc_html( $term->name ); ?></span>
					<?php if ( $show_count ) : ?><span class="cf-filter__count">(<?php echo absint( $term->count ); ?>)</span><?php endif; ?>
				</label>

			<?php else : ?>
				<label class="cf-filter__checkbox-label" for="<?php echo esc_attr( $uid ); ?>">
					<input type="checkbox" id="<?php echo esc_attr( $uid ); ?>" name="<?php echo esc_attr( $param_name ); ?>" value="<?php echo esc_attr( $term->slug ); ?>" class="cf-filter__input" <?php checked( $checked ); ?>>
					<span class="cf-filter__item-name"><?php echo esc_html( $term->name ); ?></span>
					<?php if ( $show_count ) : ?><span class="cf-filter__count">(<?php echo absint( $term->count ); ?>)</span><?php endif; ?>
				</label>
			<?php endif; ?>

			<?php if ( $has_children ) : ?>
				<button type="button" class="cf-filter__expand"
				        aria-expanded="<?php echo $descendant_checked ? 'true' : 'false'; ?>"
				        aria-label="<?php esc_attr_e( 'Toggle subcategories', 'cf-plugin' ); ?>">
					<?php echo cf_dropdown_arrow(); ?>
				</button>
			<?php endif; ?>
		</div>

		<?php if ( $has_children ) : ?>
			<ul class="cf-filter__sublist"<?php echo ! $descendant_checked ? ' style="display:none"' : ''; ?>>
				<?php foreach ( $children as $child ) : ?>
					<?php cf_render_term_item( $child, $by_id, $args ); ?>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</li>
	<?php
}

/**
 * Renders the filter form — called by [custom_filter].
 *
 * Each block gets BEM modifier classes and data attributes for JS:
 *   data-taxonomy  WP taxonomy slug
 *   data-param     cf_* GET key (from cf_param_key())
 *   data-logic     or | and | single
 *
 * To add a new filter type: add a new taxonomy === '_yourtype' branch in the
 * foreach, render your HTML, then add the matching query builder in plugin-hooks.php.
 */
function cf_render_filter_output() {
	$filters  = get_option( 'cf_filters', [] );
	$settings = get_option( 'cf_general_settings', [] );
	if ( empty( $filters ) ) return;

	$show_empty = ! empty( $settings['show_empty'] );
	$show_count = ! empty( $settings['show_count'] );
	$autosubmit = ! empty( $settings['autosubmit'] );
	$shop_url = function_exists( 'wc_get_page_id' ) ? get_permalink( wc_get_page_id( 'shop' ) ) : home_url( '/' );
	// On a taxonomy archive, submit back to the same page — not back to /shop/
	$form_action = ( is_tax() || is_product_category() || is_product_tag() )
		? get_term_link( get_queried_object() )
		: $shop_url;
	?>
	<div class="cf-filter" data-autosubmit="<?php echo $autosubmit ? '1' : '0'; ?>">
		<form class="cf-filter__form" method="get" action="<?php echo esc_url( $form_action ); ?>">

		<?php foreach ( $filters as $filter ) :
			if ( empty( $filter['taxonomy'] ) ) continue;

			if ( $filter['taxonomy'] === '_price' ) {
				$block_title = $filter['block_title'] ?? '';
				echo '<div class="cf-filter__group' . ( ! empty( $block_title ) ? ' cf-filter__group--titled' : '' ) . '">';
				if ( ! empty( $block_title ) ) {
					echo '<div class="cf-filter__block-title">' . esc_html( $block_title ) . '</div>';
				}
				cf_render_price_block( $filter );
				echo '</div><!-- /.cf-filter__group -->';
				continue;
			}

			$terms = get_terms( [ 'taxonomy' => $filter['taxonomy'], 'hide_empty' => ! $show_empty ] );
			if ( is_wp_error( $terms ) || empty( $terms ) ) continue;

			// Determine selected values early so we can reference them in the visibility check below.
			$param_base_early  = cf_param_key( $filter['taxonomy'], $filter['url_key'] ?? '' );
			$selected_vals_pre = array_filter( array_map( 'sanitize_text_field', (array) ( $_GET[ $param_base_early ] ?? [] ) ) );


			$label_type = $filter['label_type'] ?? 'selector';
			$label_text = ! empty( $filter['label_text'] ) ? $filter['label_text'] : cf_get_taxonomy_label( $filter['taxonomy'] );
			$list_style = $filter['list_style'] ?? 'checkbox';
			$logic      = $filter['logic']      ?? 'or';
			$is_open    = ! empty( $filter['dropdown_open'] );
			$param_base = cf_param_key( $filter['taxonomy'], $filter['url_key'] ?? '' );

			// Selector is always scalar; dropdown with logic=single also submits a scalar
			$param_name    = ( $logic !== 'single' && $label_type !== 'selector' ) ? $param_base . '[]' : $param_base;
			$selected_vals = array_filter( array_map( 'sanitize_text_field', (array) ( $_GET[ $param_base ] ?? [] ) ) );

			$block_title = $filter['block_title'] ?? '';
			$block_cls = implode( ' ', array_filter( [
				'cf-filter__block',
				'cf-filter__block--' . $label_type,
				$label_type === 'dropdown' ? 'cf-filter__block--list-' . $list_style : '',
				'cf-filter__block--logic-' . $logic,
			] ) );
			?>
			<div class="cf-filter__group<?php echo ! empty( $block_title ) ? ' cf-filter__group--titled' : ''; ?>">
			<?php if ( ! empty( $block_title ) ) : ?>
				<div class="cf-filter__block-title"><?php echo esc_html( $block_title ); ?></div>
			<?php endif; ?>
			<div class="<?php echo esc_attr( $block_cls ); ?>"
		     data-taxonomy="<?php echo esc_attr( $filter['taxonomy'] ); ?>"
		     data-param="<?php echo esc_attr( $param_base ); ?>"
		     data-logic="<?php echo esc_attr( $logic ); ?>">

			<?php if ( $label_type === 'selector' ) : ?>

				<div class="cf-filter__selector-wrap">
					<label class="cf-filter__selector-label" for="cf-sel-<?php echo esc_attr( $filter['taxonomy'] ); ?>">
						<?php echo esc_html( $label_text ); ?>
					</label>
					<?php // onchange="this.form.submit()" is more reliable than jQuery .on('change') when
					      // the shortcode is rendered inside a widget — avoids JS init-order issues. ?>
					<select name="<?php echo esc_attr( $param_name ); ?>"
					        id="cf-sel-<?php echo esc_attr( $filter['taxonomy'] ); ?>"
					        class="cf-filter__select"
					        onchange="this.form.submit()">
						<option value=""><?php printf( esc_html__( 'All %s', 'cf-plugin' ), esc_html( $label_text ) ); ?></option>
						<?php foreach ( $terms as $term ) : ?>
						<option value="<?php echo esc_attr( $term->slug ); ?>"<?php selected( in_array( $term->slug, $selected_vals, true ) ); ?>>
							<?php echo esc_html( $term->name ); ?><?php if ( $show_count ) echo ' (' . absint( $term->count ) . ')'; ?>
						</option>
						<?php endforeach; ?>
					</select>
				</div>

			<?php else : ?>

				<div class="cf-filter__label cf-filter__label--dropdown"
				     role="button" aria-expanded="<?php echo $is_open ? 'true' : 'false'; ?>" tabindex="0">
					<span class="cf-filter__label-text"><?php echo esc_html( $label_text ); ?></span>
					<span class="cf-filter__label-arrow" aria-hidden="true"><?php echo cf_dropdown_arrow(); ?></span>
				</div>

				<div class="cf-filter__list-wrap"<?php echo ! $is_open ? ' style="display:none"' : ''; ?>>
					<?php
					$tree      = cf_build_term_tree( $terms );
					$item_args = [
						'taxonomy'      => $filter['taxonomy'],
						'param_name'    => $param_name,
						'selected_vals' => $selected_vals,
						'list_style'    => $list_style,
						'show_count'    => $show_count,
					];
					?>
					<ul class="cf-filter__list cf-filter__list--<?php echo esc_attr( $list_style ); ?>">
						<?php foreach ( $tree['roots'] as $term ) : ?>
							<?php cf_render_term_item( $term, $tree['by_id'], $item_args ); ?>
						<?php endforeach; ?>
					</ul>
				</div>

			<?php endif; ?>

		</div>
		</div><!-- /.cf-filter__group -->
		<?php endforeach; ?>

		<?php
		/*
		 * Passthrough: any cf_* GET param that doesn't belong to a registered filter
		 * must survive form submission as a hidden input, otherwise applying one
		 * registered filter wipes all "external" cf_* values from the URL.
		 */
		$registered_params = [];
		foreach ( $filters as $f ) {
			if ( empty( $f['taxonomy'] ) ) continue;
			if ( $f['taxonomy'] === '_price' ) {
				$b = 'cf_' . ( $f['url_key'] ?: 'price' );
				$registered_params[] = $b . '_min';
				$registered_params[] = $b . '_max';
				$registered_params[] = $b . '_range';
			} else {
				$registered_params[] = cf_param_key( $f['taxonomy'], $f['url_key'] ?? '' );
			}
		}
		foreach ( $_GET as $key => $value ) {
			if ( strpos( $key, 'cf_' ) !== 0 ) continue;
			if ( in_array( $key, $registered_params, true ) ) continue;
			foreach ( (array) $value as $v ) {
				echo '<input type="hidden" name="' . esc_attr( $key ) . '[]" value="' . esc_attr( sanitize_text_field( $v ) ) . '">';
			}
		}
		?>

		<?php if ( ! $autosubmit ) : ?>
			<div class="cf-filter__actions">
				<button type="submit" class="cf-filter__submit"><?php esc_html_e( 'Apply filters', 'cf-plugin' ); ?></button>
				<a href="<?php echo esc_url( $shop_url ); ?>" class="cf-filter__reset"><?php esc_html_e( 'Reset', 'cf-plugin' ); ?></a>
			</div>
		<?php endif; ?>

		</form>
	</div>
	<?php
}

/**
 * Renders the price filter block — inputs or slider variant.
 *
 * URL params: cf_{url_key}_min / cf_{url_key}_max (default: cf_price_min/max)
 *
 * Slider: two stacked <input type="range"> share a visual track. Values are
 * written to hidden inputs on mouseup and the form is submitted. See filter.js.
 *
 * Bounds: uses admin-set min/max if provided, otherwise queries the DB via
 * cf_get_min_price() / cf_get_max_price().
 */
function cf_render_price_block( $filter ) {
	$price_type        = $filter['price_type']        ?? 'inputs';
	$price_as_dropdown = ! empty( $filter['price_as_dropdown'] );
	$is_open           = ! $price_as_dropdown || ! empty( $filter['dropdown_open'] );
	$label_text = ! empty( $filter['label_text'] ) ? $filter['label_text'] : __( 'Price', 'cf-plugin' );
	$base       = 'cf_' . ( $filter['url_key'] ?: 'price' );
	$param_min  = $base . '_min';
	$param_max  = $base . '_max';

	$cur_min   = isset( $_GET[ $param_min ] ) ? floatval( $_GET[ $param_min ] ) : '';
	$cur_max   = isset( $_GET[ $param_max ] ) ? floatval( $_GET[ $param_max ] ) : '';
	$bound_min = ( $filter['price_min'] !== '' && $filter['price_min'] !== null ) ? floatval( $filter['price_min'] ) : cf_get_min_price();
	$bound_max = ( $filter['price_max'] !== '' && $filter['price_max'] !== null ) ? floatval( $filter['price_max'] ) : cf_get_max_price();
	?>
	<div class="cf-filter__block cf-filter__block--price<?php echo $price_as_dropdown ? ' cf-filter__block--dropdown' : ''; ?>"
	     data-type="<?php echo esc_attr( $price_type ); ?>"
	     data-min="<?php echo esc_attr( $bound_min ); ?>"
	     data-max="<?php echo esc_attr( $bound_max ); ?>">

		<?php if ( $price_as_dropdown ) : ?>
		<div class="cf-filter__label cf-filter__label--dropdown"
		     role="button" aria-expanded="<?php echo $is_open ? 'true' : 'false'; ?>" tabindex="0">
			<span class="cf-filter__label-text"><?php echo esc_html( $label_text ); ?></span>
			<span class="cf-filter__label-arrow" aria-hidden="true"><?php echo cf_dropdown_arrow(); ?></span>
		</div>
		<?php else : ?>
		<div class="cf-filter__label cf-filter__label--static">
			<span class="cf-filter__label-text"><?php echo esc_html( $label_text ); ?></span>
		</div>
		<?php endif; ?>

		<div class="cf-filter__list-wrap"<?php echo ( $price_as_dropdown && ! $is_open ) ? ' style="display:none"' : ''; ?>>

		<?php if ( $price_type === 'slider' ) : ?>
			<div class="cf-price-slider">
				<div class="cf-price-slider__track"><div class="cf-price-slider__range"></div></div>
				<input type="range" class="cf-price-slider__input cf-price-slider__input--min" min="<?php echo esc_attr( $bound_min ); ?>" max="<?php echo esc_attr( $bound_max ); ?>" value="<?php echo esc_attr( $cur_min !== '' ? $cur_min : $bound_min ); ?>" step="1">
				<input type="range" class="cf-price-slider__input cf-price-slider__input--max" min="<?php echo esc_attr( $bound_min ); ?>" max="<?php echo esc_attr( $bound_max ); ?>" value="<?php echo esc_attr( $cur_max !== '' ? $cur_max : $bound_max ); ?>" step="1">
			</div>
			<div class="cf-price-slider__labels">
				<span class="cf-price-slider__input-wrap" data-symbol="<?php echo esc_attr( cf_price_symbol() ); ?>">
					<input type="number" class="cf-price-slider__val cf-price-slider__val--min"
						value="<?php echo esc_attr( (int) ( $cur_min !== '' ? $cur_min : $bound_min ) ); ?>"
						min="<?php echo esc_attr( $bound_min ); ?>" max="<?php echo esc_attr( $bound_max ); ?>" step="1">
				</span>
				<span class="cf-price-slider__sep">–</span>
				<span class="cf-price-slider__input-wrap" data-symbol="<?php echo esc_attr( cf_price_symbol() ); ?>">
					<input type="number" class="cf-price-slider__val cf-price-slider__val--max"
						value="<?php echo esc_attr( (int) ( $cur_max !== '' ? $cur_max : $bound_max ) ); ?>"
						min="<?php echo esc_attr( $bound_min ); ?>" max="<?php echo esc_attr( $bound_max ); ?>" step="1">
				</span>
			</div>
			<?php
			// Hidden inputs are disabled until the user moves the slider.
			// This prevents empty price params being submitted when other filters change.
			// JS enables them and sets their values only on slider release (change event).
			$price_active = ( $cur_min !== '' || $cur_max !== '' );
			?>
			<input type="hidden" name="<?php echo esc_attr( $param_min ); ?>" class="cf-price-hidden-min"
			       value="<?php echo esc_attr( $cur_min ); ?>"<?php echo ! $price_active ? ' disabled' : ''; ?>>
			<input type="hidden" name="<?php echo esc_attr( $param_max ); ?>" class="cf-price-hidden-max"
			       value="<?php echo esc_attr( $cur_max ); ?>"<?php echo ! $price_active ? ' disabled' : ''; ?>>

			<?php elseif ( $price_type === 'dropdown' ) :
				// Build ranges from filter config or fall back to automatic generation.
				// Stored as JSON string in $filter['price_ranges'], e.g. [[0,100],[100,500],[500,1000]]
				$ranges = [];
				if ( ! empty( $filter['price_ranges'] ) ) {
					$decoded = json_decode( $filter['price_ranges'], true );
					if ( is_array( $decoded ) ) $ranges = $decoded;
				}
				// Auto-generate ranges if none defined.
				if ( empty( $ranges ) ) {
					$ranges = cf_generate_price_ranges( $bound_min, $bound_max );
				}
				// Current selected value: encoded as "min-max" in a single GET param.
				$range_param  = $base . '_range';
				$selected_rng = sanitize_text_field( $_GET[ $range_param ] ?? '' );
				// Decode selected range back to min/max for query (handled in plugin-hooks.php).
			?>
				<ul class="cf-filter__list cf-filter__list--radio">
					<?php foreach ( $ranges as $i => $range ) :
						list( $rmin, $rmax ) = $range;
						$val     = absint( $rmin ) . '-' . absint( $rmax );
						$label   = cf_format_price( $rmin ) . ' – ' . cf_format_price( $rmax );
						$checked = ( $selected_rng === $val );
						$uid     = 'cf-price-range-' . $i;
					?>
					<li class="cf-filter__item">
						<label class="cf-filter__radio-label<?php echo $checked ? ' is-checked' : ''; ?>" for="<?php echo esc_attr( $uid ); ?>">
							<span class="cf-filter__radio-dot" aria-hidden="true"></span>
							<input type="radio"
								id="<?php echo esc_attr( $uid ); ?>"
								name="<?php echo esc_attr( $range_param ); ?>"
								value="<?php echo esc_attr( $val ); ?>"
								class="cf-filter__input cf-sr-only"
								<?php checked( $checked ); ?>>
							<span class="cf-filter__item-name"><?php echo esc_html( $label ); ?></span>
						</label>
					</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<div class="cf-price-inputs">
					<label class="cf-price-inputs__label" for="cf-price-min"><?php esc_html_e( 'From', 'cf-plugin' ); ?></label>
					<input type="number" id="cf-price-min" name="<?php echo esc_attr( $param_min ); ?>" class="cf-price-inputs__input cf-price-inputs__input--min" value="<?php echo esc_attr( $cur_min ); ?>" min="<?php echo esc_attr( $bound_min ); ?>" max="<?php echo esc_attr( $bound_max ); ?>" placeholder="<?php echo esc_attr( $bound_min ); ?>" step="1">
					<span class="cf-price-inputs__sep">–</span>
					<label class="cf-price-inputs__label" for="cf-price-max"><?php esc_html_e( 'To', 'cf-plugin' ); ?></label>
					<input type="number" id="cf-price-max" name="<?php echo esc_attr( $param_max ); ?>" class="cf-price-inputs__input cf-price-inputs__input--max" value="<?php echo esc_attr( $cur_max ); ?>" min="<?php echo esc_attr( $bound_min ); ?>" max="<?php echo esc_attr( $bound_max ); ?>" placeholder="<?php echo esc_attr( $bound_max ); ?>" step="1">
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

/**
 * Auto-generates sensible price ranges based on min/max bounds.
 * Returns array of [min, max] pairs.
 */
function cf_generate_price_ranges( $min, $max ) {
	$span   = $max - $min;
	$step   = (int) pow( 10, floor( log10( $span / 4 ) ) ); // nice round step
	$step   = max( $step, 1 );
	$ranges = [];
	$cur    = (int) floor( $min / $step ) * $step;
	while ( $cur < $max ) {
		$next     = $cur + $step;
		$ranges[] = [ $cur, min( $next, $max ) ];
		$cur      = $next;
	}
	return $ranges;
}
/**
 * Returns the set of term slugs for a taxonomy that have at least one
 * product visible in the WooCommerce catalog (catalog_visibility is not
 * 'hidden' or 'search').
 *
 * Uses a single JOIN query so it scales to large catalogues without
 * loading every product into PHP. Results are cached per taxonomy per
 * request via a static array.
 *
 * @param string $taxonomy  WP taxonomy slug (e.g. 'product_cat', 'pa_color').
 * @return array<string>    Flat array of term slugs that have visible products.
 */


// Returns floor of cheapest product price; used as slider/input lower bound.
function cf_get_min_price() {
	global $wpdb;
	$v = $wpdb->get_var( "SELECT MIN(CAST(meta_value AS DECIMAL(10,2))) FROM {$wpdb->postmeta} WHERE meta_key='_price' AND meta_value!='' AND meta_value>0" );
	return $v ? (int) floor( $v ) : 0;
}

// Returns ceil of most expensive product price; used as slider/input upper bound.
function cf_get_max_price() {
	global $wpdb;
	$v = $wpdb->get_var( "SELECT MAX(CAST(meta_value AS DECIMAL(10,2))) FROM {$wpdb->postmeta} WHERE meta_key='_price' AND meta_value!=''" );
	return $v ? (int) ceil( $v ) : 1000;
}

// Formats a number as a price string using WC's currency settings.
// Falls back to the raw number if WC is unavailable.
function cf_price_symbol() {
    $s      = get_option( 'cf_general_settings', [] );
    $custom = $s['price_currency'] ?? '';

    if ( $custom !== '' ) {
        return $custom;
    }

    // Fall back to the active WooCommerce currency symbol.
    if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
        return html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
    }

    return '';
}
function cf_format_price( $price ) {
    $symbol = cf_price_symbol();
    $value  = (string) (int) $price;
    return $symbol !== '' ? $value . $symbol : $value;
}
/**
 * Renders active-filter chips — called by [active_filters].
 *
 * Each chip links to the current URL with only that value removed.
 * "Clear all" links to bare $shop_url (no filter params).
 * The wrapper is hidden via CSS when empty; .cf-active-filters--visible shows it.
 */
function cf_render_active_filters_bar( $filters, $shop_url ) {
	$chips = [];

	foreach ( $filters as $filter ) {
		if ( empty( $filter['taxonomy'] ) ) continue;

		if ( $filter['taxonomy'] === '_price' ) {
			$base       = 'cf_' . ( $filter['url_key'] ?: 'price' );
			$price_type = $filter['price_type'] ?? 'inputs';
			$label_text = ! empty( $filter['label_text'] ) ? $filter['label_text'] : __( 'Price', 'cf-plugin' );

			if ( $price_type === 'dropdown' ) {
				// Dropdown range: single param like "100-500"
				$range_param  = $base . '_range';
				$selected_rng = isset( $_GET[ $range_param ] ) && $_GET[ $range_param ] !== '' ? sanitize_text_field( $_GET[ $range_param ] ) : null;
				if ( $selected_rng === null ) continue;

				$parts = explode( '-', $selected_rng, 2 );
				$chip_label = ( count( $parts ) === 2 && is_numeric( $parts[0] ) && is_numeric( $parts[1] ) )
					? cf_format_price( $parts[0] ) . ' – ' . cf_format_price( $parts[1] )
					: $selected_rng;

				$query_args = $_GET;
				unset( $query_args[ $range_param ] );
				$chips[] = [
					'label'      => $label_text . ': ' . $chip_label,
					'remove_url' => add_query_arg( $query_args, $shop_url ),
				];
			} else {
				// Inputs / slider: separate _min / _max params
				$cur_min = isset( $_GET[ $base . '_min' ] ) && $_GET[ $base . '_min' ] !== '' ? floatval( $_GET[ $base . '_min' ] ) : null;
				$cur_max = isset( $_GET[ $base . '_max' ] ) && $_GET[ $base . '_max' ] !== '' ? floatval( $_GET[ $base . '_max' ] ) : null;
				if ( $cur_min === null && $cur_max === null ) continue;

				$parts = array_filter( [
					$cur_min !== null ? cf_format_price( $cur_min ) : null,
					$cur_max !== null ? cf_format_price( $cur_max ) : null,
				] );
				$query_args = $_GET;
				unset( $query_args[ $base . '_min' ], $query_args[ $base . '_max' ] );
				$chips[] = [
					'label'      => $label_text . ': ' . implode( ' – ', $parts ),
					'remove_url' => add_query_arg( $query_args, $shop_url ),
				];
			}
			continue;
		}

		$param_base    = cf_param_key( $filter['taxonomy'], $filter['url_key'] ?? '' );
		$selected_vals = array_filter( array_map( 'sanitize_text_field', (array) ( $_GET[ $param_base ] ?? [] ) ) );
		if ( empty( $selected_vals ) ) continue;

		$label_text = ! empty( $filter['label_text'] ) ? $filter['label_text'] : cf_get_taxonomy_label( $filter['taxonomy'] );

		foreach ( $selected_vals as $slug ) {
			$term = get_term_by( 'slug', $slug, $filter['taxonomy'] );
			if ( ! $term ) continue;

			$query_args = $_GET;
			$remaining = array_values( array_filter( (array) ( $query_args[ $param_base ] ?? [] ), fn( $v ) => $v !== $slug ) );
			if ( empty( $remaining ) ) {
				unset( $query_args[ $param_base ] );
			} else {
				$query_args[ $param_base ] = $remaining;
			}

			$chips[] = [
				'label'      => $label_text . ': ' . $term->name,
				'remove_url' => add_query_arg( $query_args, $shop_url ),
			];
		}
	}

	// ── Fallback: chips for cf_* params not covered by any configured filter ──
	// Handles params injected externally (product meta links, taxonomy redirect)
	// that are valid WC taxonomy filters but not listed in the Taxonomies tab.
	//
	// Reverse-lookup: for every unhandled cf_* param, find the WP taxonomy
	// whose cf_param_key() output matches. Direct string-strip of "cf_" fails
	// because WP taxonomy slugs (product_cat, pa_color) never equal the short
	// key (category, color) — we must check all product taxonomies explicitly.

	$handled_params = [];
	foreach ( $filters as $filter ) {
		if ( empty( $filter['taxonomy'] ) ) continue;
		if ( $filter['taxonomy'] === '_price' ) {
			$b = 'cf_' . ( $filter['url_key'] ?: 'price' );
			$handled_params[] = $b . '_min';
			$handled_params[] = $b . '_max';
			$handled_params[] = $b . '_range';
		} else {
			$handled_params[] = cf_param_key( $filter['taxonomy'], $filter['url_key'] ?? '' );
		}
	}

	// Map every product taxonomy to the param key it would produce (no url_key).
	$tax_param_map = [];
	foreach ( get_object_taxonomies( 'product', 'names' ) as $tax ) {
		$tax_param_map[ cf_param_key( $tax ) ] = $tax;
	}

	foreach ( $_GET as $key => $value ) {
		if ( strpos( $key, 'cf_' ) !== 0 ) continue;
		if ( preg_match( '/_min$|_max$|_range$/', $key ) ) continue;
		if ( in_array( $key, $handled_params, true ) ) continue;

		$selected_vals = array_values( array_filter( array_map( 'sanitize_text_field', (array) $value ) ) );
		if ( empty( $selected_vals ) ) continue;

		$real_tax   = $tax_param_map[ $key ] ?? null;
		$label_text = $real_tax
			? cf_get_taxonomy_label( $real_tax )
			: ucwords( str_replace( '_', ' ', preg_replace( '/^cf_/', '', $key ) ) );

		foreach ( $selected_vals as $slug ) {
			$term       = $real_tax ? get_term_by( 'slug', $slug, $real_tax ) : null;
			$term_label = $term ? $term->name : ucwords( str_replace( '-', ' ', $slug ) );

			$query_args = $_GET;
			$remaining  = array_values( array_filter(
				(array) ( $query_args[ $key ] ?? [] ),
				fn( $v ) => $v !== $slug
			) );
			if ( empty( $remaining ) ) {
				unset( $query_args[ $key ] );
			} else {
				$query_args[ $key ] = $remaining;
			}

			$chips[] = [
				'label'      => $label_text . ': ' . $term_label,
				'remove_url' => add_query_arg( $query_args, $shop_url ),
			];
		}
	}

	$has_chips = ! empty( $chips );
	?>
	<div class="cf-active-filters<?php echo $has_chips ? ' cf-active-filters--visible' : ''; ?>">
		<?php foreach ( $chips as $chip ) : ?>
			<a href="<?php echo esc_url( $chip['remove_url'] ); ?>" class="cf-active-filters__chip">
				<span class="cf-active-filters__chip-text"><?php echo esc_html( $chip['label'] ); ?></span>
				<span class="cf-active-filters__chip-remove" aria-hidden="true">&#10005;</span>
			</a>
		<?php endforeach; ?>
		<?php if ( $has_chips ) : ?>
			<a href="<?php echo esc_url( $shop_url ); ?>" class="cf-active-filters__clear"><?php esc_html_e( 'Clear all', 'cf-plugin' ); ?></a>
		<?php endif; ?>
	</div>
	<?php
}