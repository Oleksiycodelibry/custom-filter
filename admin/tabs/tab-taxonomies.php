<?php
defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────
// Save handler
// ─────────────────────────────────────────────
add_action( 'admin_post_cf_save_taxonomies', 'cf_save_taxonomies' );
function cf_save_taxonomies() {
	check_admin_referer( 'cf_save_taxonomies' );
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

	$filters = [];

	if ( ! empty( $_POST['cf_filters'] ) && is_array( $_POST['cf_filters'] ) ) {
		foreach ( $_POST['cf_filters'] as $filter ) {
			$taxonomy = sanitize_key( $filter['taxonomy'] ?? '' );

			$row = [
				'taxonomy'    => $taxonomy,
				'label_text'  => sanitize_text_field( $filter['label_text'] ?? '' ),
				'block_title' => sanitize_text_field( $filter['block_title'] ?? '' ),
				'url_key'     => sanitize_key( $filter['url_key'] ?? '' ),
			];

			if ( $taxonomy === '_price' ) {
				// Price-specific fields
				$row['dropdown_open']    = ! empty( $filter['dropdown_open'] );
				$row['price_as_dropdown'] = ! empty( $filter['price_as_dropdown'] );
				$row['price_type']  = in_array( $filter['price_type'] ?? '', [ 'inputs', 'slider', 'dropdown' ] )
					? $filter['price_type']
					: 'inputs';
				// Validate and store price ranges JSON (dropdown type).
				$raw_ranges = $filter['price_ranges'] ?? '';
				$decoded    = json_decode( $raw_ranges, true );
				$row['price_ranges'] = ( is_array( $decoded ) ) ? wp_json_encode( $decoded ) : '';
				$row['price_min']   = is_numeric( $filter['price_min'] ?? '' )
					? floatval( $filter['price_min'] )
					: '';
				$row['price_max']   = is_numeric( $filter['price_max'] ?? '' )
					? floatval( $filter['price_max'] )
					: '';
			} else {
				// Taxonomy-specific fields
				$row['label_type']    = sanitize_key( $filter['label_type']   ?? 'selector' );
				$row['dropdown_open'] = ! empty( $filter['dropdown_open'] );
				$row['list_style']    = sanitize_key( $filter['list_style']   ?? 'checkbox' );
				$row['logic']         = sanitize_key( $filter['logic']        ?? 'or' );
			}

			$filters[] = $row;
		}
	}

	update_option( 'cf_filters', $filters );
	wp_redirect( admin_url( 'admin.php?page=cf-settings&tab=taxonomies&saved=1' ) );
	exit;
}

// ─────────────────────────────────────────────
// Tab render
// ─────────────────────────────────────────────
function cf_render_tab_taxonomies() {
	$filters    = get_option( 'cf_filters', [] );
	$taxonomies = cf_get_available_taxonomies();
	?>
	<?php if ( isset( $_GET['saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Filters saved.', 'cf-plugin' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cf-taxonomies-form">
		<?php wp_nonce_field( 'cf_save_taxonomies' ); ?>
		<input type="hidden" name="action" value="cf_save_taxonomies">

		<div id="cf-filters-list">
			<?php foreach ( $filters as $i => $filter ) : ?>
				<?php cf_render_filter_row( $i, $filter, $taxonomies ); ?>
			<?php endforeach; ?>
		</div>

		<button type="button" id="cf-add-filter" class="button button-secondary">
			&#43; <?php esc_html_e( 'Add Filter', 'cf-plugin' ); ?>
		</button>

		<p class="submit">
			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Save Filters', 'cf-plugin' ); ?>
			</button>
		</p>
	</form>

	<template id="cf-filter-row-template">
		<?php cf_render_filter_row( '__INDEX__', [], $taxonomies ); ?>
	</template>
	<?php
}

// ─────────────────────────────────────────────
// Single filter row
// ─────────────────────────────────────────────
function cf_render_filter_row( $index, $data, $taxonomies ) {
	$taxonomy      = $data['taxonomy']      ?? '';
	$label_type    = $data['label_type']    ?? 'selector';
	$label_text    = $data['label_text']    ?? '';
	$url_key       = $data['url_key']       ?? '';
	$dropdown_open = $data['dropdown_open'] ?? false;
	$list_style    = $data['list_style']    ?? 'checkbox';
	$logic         = $data['logic']         ?? 'or';
	$price_type        = $data['price_type']        ?? 'inputs';
	$price_min         = $data['price_min']         ?? '';
	$price_max         = $data['price_max']         ?? '';
	$price_ranges      = $data['price_ranges']      ?? '';
	$price_as_dropdown = $data['price_as_dropdown'] ?? false;
	$block_title   = $data['block_title']   ?? '';

	$is_price = ( $taxonomy === '_price' );
	$name     = "cf_filters[$index]";
	?>
	<div class="cf-filter-row" data-index="<?php echo esc_attr( $index ); ?>">
		<div class="cf-filter-row__header">
			<span class="cf-filter-row__drag dashicons dashicons-menu"></span>
			<strong class="cf-filter-row__title">
				<?php
				if ( $is_price ) {
					esc_html_e( 'Price', 'cf-plugin' );
				} elseif ( $taxonomy ) {
					echo esc_html( cf_get_taxonomy_label( $taxonomy ) );
				} else {
					esc_html_e( 'New Filter', 'cf-plugin' );
				}
				?>
			</strong>
			<button type="button" class="cf-filter-row__toggle button-link">&#9660;</button>
			<button type="button" class="cf-filter-row__remove button-link">&#10005; <?php esc_html_e( 'Remove', 'cf-plugin' ); ?></button>
		</div>

		<div class="cf-filter-row__body">

			<!-- 1. Taxonomy / filter type -->
			<!-- 2b. Block title (always, optional) -->
			<div class="cf-field">
				<label><?php esc_html_e( 'Block title', 'cf-plugin' ); ?></label>
				<input type="text"
					name="<?php echo esc_attr( $name ); ?>[block_title]"
					value="<?php echo esc_attr( $block_title ); ?>"
					class="regular-text"
					placeholder="<?php esc_attr_e( 'Shown above the filter block. Leave empty to hide.', 'cf-plugin' ); ?>">
			</div>

			<!-- 3. URL key (always) -->
			<div class="cf-field">
				<label><?php esc_html_e( 'Filter type', 'cf-plugin' ); ?></label>
				<select name="<?php echo esc_attr( $name ); ?>[taxonomy]"
				        class="cf-select cf-taxonomy-select">
					<option value=""><?php esc_html_e( '— Select —', 'cf-plugin' ); ?></option>
					<optgroup label="<?php esc_attr_e( 'Special', 'cf-plugin' ); ?>">
						<option value="_price" <?php selected( $taxonomy, '_price' ); ?>>
							<?php esc_html_e( 'Price', 'cf-plugin' ); ?>
						</option>
					</optgroup>
					<optgroup label="<?php esc_attr_e( 'Taxonomies', 'cf-plugin' ); ?>">
						<?php foreach ( $taxonomies as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $taxonomy, $slug ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</optgroup>
				</select>
			</div>

			<!-- 2. Label text (always) -->
			<div class="cf-field">
				<label><?php esc_html_e( 'Label text', 'cf-plugin' ); ?></label>
				<input type="text"
				       name="<?php echo esc_attr( $name ); ?>[label_text]"
				       value="<?php echo esc_attr( $label_text ); ?>"
				       class="regular-text"
				       placeholder="<?php esc_attr_e( 'e.g. Price', 'cf-plugin' ); ?>">
			</div>

			<!-- 3. URL key (always) -->
			<div class="cf-field">
				<label><?php esc_html_e( 'URL key', 'cf-plugin' ); ?></label>
				<input type="text"
				       name="<?php echo esc_attr( $name ); ?>[url_key]"
				       value="<?php echo esc_attr( $url_key ); ?>"
				       class="small-text"
				       placeholder="<?php echo esc_attr( $is_price ? 'price' : ( preg_replace( '/^pa_/', '', $taxonomy ) ?: 'e.g. q' ) ); ?>">
				<p class="description"><?php esc_html_e( 'Short key used in the URL. Leave empty to use the default.', 'cf-plugin' ); ?></p>
			</div>

			<!-- ── PRICE-ONLY FIELDS ─────────────────────────────────── -->
			<div class="cf-field cf-field--price-only" <?php echo ! $is_price ? 'style="display:none"' : ''; ?>>
				<label><?php esc_html_e( 'Price input type', 'cf-plugin' ); ?></label>
				<div class="cf-radio-group">
					<label class="cf-radio-label">
						<input type="radio"
						       name="<?php echo esc_attr( $name ); ?>[price_type]"
						       value="inputs"
						       <?php checked( $price_type, 'inputs' ); ?>>
						<?php esc_html_e( 'Two text inputs (From / To)', 'cf-plugin' ); ?>
					</label>
					<label class="cf-radio-label">
						<input type="radio"
						       name="<?php echo esc_attr( $name ); ?>[price_type]"
						       value="slider"
						       <?php checked( $price_type, 'slider' ); ?>>
						<?php esc_html_e( 'Range slider', 'cf-plugin' ); ?>
					</label>
					<label class="cf-radio-label">
						<input type="radio"
						       name="<?php echo esc_attr( $name ); ?>[price_type]"
						       value="dropdown"
						       <?php checked( $price_type, 'dropdown' ); ?>>
						<?php esc_html_e( 'Dropdown ranges', 'cf-plugin' ); ?>
					</label>
				</div>
			</div>

			<div class="cf-field cf-field--price-only" <?php echo ! $is_price ? 'style="display:none"' : ''; ?>>
				<label><?php esc_html_e( 'Price ranges (dropdown)', 'cf-plugin' ); ?></label>
				<textarea name="<?php echo esc_attr( $name ); ?>[price_ranges]"
				          class="large-text code"
				          rows="4"
				          placeholder="[[0,100],[100,500],[500,1000],[1000,5000]]"><?php echo esc_textarea( $price_ranges ); ?></textarea>
				<p class="description"><?php esc_html_e( 'JSON array of [min, max] pairs. Leave empty to auto-generate from product prices.', 'cf-plugin' ); ?></p>
			</div>

			<div class="cf-field cf-field--price-only" <?php echo ! $is_price ? 'style="display:none"' : ''; ?>>
				<label><?php esc_html_e( 'Min price', 'cf-plugin' ); ?></label>
				<input type="number"
				       name="<?php echo esc_attr( $name ); ?>[price_min]"
				       value="<?php echo esc_attr( $price_min ); ?>"
				       class="small-text"
				       min="0" step="1"
				       placeholder="0">
				<p class="description"><?php esc_html_e( 'Leave empty to auto-detect from products.', 'cf-plugin' ); ?></p>
			</div>

			<div class="cf-field cf-field--price-only" <?php echo ! $is_price ? 'style="display:none"' : ''; ?>>
				<label><?php esc_html_e( 'Max price', 'cf-plugin' ); ?></label>
				<input type="number"
				       name="<?php echo esc_attr( $name ); ?>[price_max]"
				       value="<?php echo esc_attr( $price_max ); ?>"
				       class="small-text"
				       min="0" step="1"
				       placeholder="<?php esc_attr_e( 'auto', 'cf-plugin' ); ?>">
				<p class="description"><?php esc_html_e( 'Leave empty to auto-detect from products.', 'cf-plugin' ); ?></p>
			</div>

			<div class="cf-field cf-field--price-only" <?php echo ! $is_price ? 'style="display:none"' : ''; ?>>
				<label><?php esc_html_e( 'Show as dropdown', 'cf-plugin' ); ?></label>
				<label class="cf-toggle">
					<input type="checkbox"
						name="<?php echo esc_attr( $name ); ?>[price_as_dropdown]"
						value="1"
						<?php checked( $price_as_dropdown ); ?>>
					<span class="cf-toggle__track"></span>
				</label>
				<p class="description"><?php esc_html_e( 'Wrap the price filter in a collapsible dropdown header, like other filter blocks.', 'cf-plugin' ); ?></p>
			</div>

			<div class="cf-field cf-field--price-only" <?php echo ! $is_price ? 'style="display:none"' : ''; ?>>
				<label><?php esc_html_e( 'Default state', 'cf-plugin' ); ?></label>
				<label class="cf-toggle">
					<input type="checkbox"
						name="<?php echo esc_attr( $name ); ?>[dropdown_open]"
						value="1"
						<?php checked( $dropdown_open ); ?>>
					<?php esc_html_e( 'Open by default', 'cf-plugin' ); ?>
				</label>
			</div>

			<!-- ── TAXONOMY-ONLY FIELDS ──────────────────────────────── -->
			<div class="cf-field cf-field--taxonomy-only" <?php echo $is_price ? 'style="display:none"' : ''; ?>>
				<label><?php esc_html_e( 'Display type', 'cf-plugin' ); ?></label>
				<select name="<?php echo esc_attr( $name ); ?>[label_type]"
				        class="cf-select cf-label-type-select">
					<option value="selector" <?php selected( $label_type, 'selector' ); ?>><?php esc_html_e( 'Selector (native <select>)', 'cf-plugin' ); ?></option>
					<option value="dropdown" <?php selected( $label_type, 'dropdown' ); ?>><?php esc_html_e( 'Dropdown Label (collapsible list)', 'cf-plugin' ); ?></option>
				</select>
			</div>

			<div class="cf-field cf-field--taxonomy-only cf-field--dropdown-only"
			     <?php echo ( $is_price || $label_type !== 'dropdown' ) ? 'style="display:none"' : ''; ?>>
				<label><?php esc_html_e( 'Default state', 'cf-plugin' ); ?></label>
				<label class="cf-toggle">
					<input type="checkbox"
					       name="<?php echo esc_attr( $name ); ?>[dropdown_open]"
					       value="1"
					       <?php checked( $dropdown_open ); ?>>
					<?php esc_html_e( 'Open by default', 'cf-plugin' ); ?>
				</label>
			</div>

			<div class="cf-field cf-field--taxonomy-only cf-field--dropdown-only"
			     <?php echo ( $is_price || $label_type !== 'dropdown' ) ? 'style="display:none"' : ''; ?>>
				<label><?php esc_html_e( 'List item style', 'cf-plugin' ); ?></label>
				<div class="cf-radio-group">
					<?php foreach ( [ 'checkbox' => __( 'Checkbox', 'cf-plugin' ), 'radio' => __( 'Radio (visual only)', 'cf-plugin' ), 'label' => __( 'Label / Tag', 'cf-plugin' ) ] as $val => $lbl ) : ?>
						<label class="cf-radio-label">
							<input type="radio"
							       name="<?php echo esc_attr( $name ); ?>[list_style]"
							       value="<?php echo esc_attr( $val ); ?>"
							       <?php checked( $list_style, $val ); ?>>
							<?php echo esc_html( $lbl ); ?>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="cf-field cf-field--taxonomy-only" <?php echo $is_price ? 'style="display:none"' : ''; ?>>
				<label><?php esc_html_e( 'Filter logic', 'cf-plugin' ); ?></label>
				<div class="cf-radio-group">
					<?php foreach ( [ 'or' => __( 'OR — any selected', 'cf-plugin' ), 'and' => __( 'AND — all selected', 'cf-plugin' ), 'single' => __( 'Single choice', 'cf-plugin' ) ] as $val => $lbl ) : ?>
						<label class="cf-radio-label">
							<input type="radio"
							       name="<?php echo esc_attr( $name ); ?>[logic]"
							       value="<?php echo esc_attr( $val ); ?>"
							       <?php checked( $logic, $val ); ?>>
							<?php echo esc_html( $lbl ); ?>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

		</div><!-- /.cf-filter-row__body -->
	</div><!-- /.cf-filter-row -->
	<?php
}

// ─────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────
function cf_get_available_taxonomies() {
	$result = [];

	if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
		foreach ( wc_get_attribute_taxonomies() as $attr ) {
			$slug            = wc_attribute_taxonomy_name( $attr->attribute_name );
			$result[ $slug ] = sprintf( __( 'Attribute: %s', 'cf-plugin' ), $attr->attribute_label );
		}
	}

	foreach ( [ 'product_cat' => __( 'Product Categories', 'cf-plugin' ), 'product_tag' => __( 'Product Tags', 'cf-plugin' ), 'product_brand' => __( 'Product Brand', 'cf-plugin' ) ] as $slug => $label ) {
		if ( taxonomy_exists( $slug ) ) $result[ $slug ] = $label;
	}

	return apply_filters( 'cf_available_taxonomies', $result );
}

function cf_get_taxonomy_label( $slug ) {
	$taxes = cf_get_available_taxonomies();
	return $taxes[ $slug ] ?? $slug;
}