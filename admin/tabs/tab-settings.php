<?php
defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────
// Save handler
// ─────────────────────────────────────────────
add_action( 'admin_post_cf_save_settings', 'cf_save_general_settings' );
function cf_save_general_settings() {
	check_admin_referer( 'cf_save_settings' );
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

	// Sanitize SVG: allow only safe inline SVG (strip script, event attrs, etc.)
	$raw_svg    = wp_unslash( $_POST['dropdown_arrow_svg'] ?? '' );
	$clean_svg  = cf_sanitize_svg( $raw_svg );

	$settings = [
		'ajax_filter'         => ! empty( $_POST['ajax_filter'] ),
		'autosubmit'          => ! empty( $_POST['autosubmit'] ),
		'show_empty'          => ! empty( $_POST['show_empty'] ),
		'show_count'          => ! empty( $_POST['show_count'] ),
		'show_active_filters' => ! empty( $_POST['show_active_filters'] ),
		'dropdown_arrow_svg'  => $clean_svg,
		'price_currency'      => sanitize_text_field( wp_unslash( $_POST['price_currency'] ?? '' ) ), 
	];

	update_option( 'cf_general_settings', $settings );
	wp_redirect( admin_url( 'admin.php?page=cf-settings&tab=settings&saved=1' ) );
	exit;
}

// ─────────────────────────────────────────────
// SVG sanitizer — strips dangerous attributes
// and elements while keeping valid SVG markup
// ─────────────────────────────────────────────
function cf_sanitize_svg( $svg ) {
	$svg = trim( $svg );
	if ( $svg === '' ) return '';

	// Must start with <svg
	if ( stripos( $svg, '<svg' ) === false ) return '';

	// Strip script tags and event handler attributes
	$svg = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $svg );
	$svg = preg_replace( '/\bon\w+\s*=/i', 'data-removed=', $svg );
	$svg = preg_replace( '/href\s*=\s*["\']?\s*javascript:/i', 'href="removed:', $svg );

	return $svg;
}

// ─────────────────────────────────────────────
// Tab render
// ─────────────────────────────────────────────
function cf_render_tab_settings() {
	$s = get_option( 'cf_general_settings', [
		'ajax_filter'         => false,
		'autosubmit'          => true,
		'show_empty'          => false,
		'show_count'          => true,
		'show_active_filters' => false,
		'dropdown_arrow_svg'  => '',
		'price_currency'      => '',
	] );

	$default_arrow_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 4L6 8L10 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	$current_svg       = ! empty( $s['dropdown_arrow_svg'] ) ? $s['dropdown_arrow_svg'] : '';
	?>

	<?php if ( isset( $_GET['saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'cf-plugin' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'cf_save_settings' ); ?>
		<input type="hidden" name="action" value="cf_save_settings">

		<table class="form-table cf-settings-table" role="presentation">

			<tr>
				<th scope="row"><?php esc_html_e( 'Autosubmit', 'cf-plugin' ); ?></th>
				<td>
					<label class="cf-toggle">
						<input type="checkbox" name="autosubmit" value="1" <?php checked( $s['autosubmit'] ?? false ); ?>>
						<span class="cf-toggle__track"></span>
					</label>
					<p class="description"><?php esc_html_e( 'Apply filter automatically on selection, without a button.', 'cf-plugin' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Show empty terms', 'cf-plugin' ); ?></th>
				<td>
					<label class="cf-toggle">
						<input type="checkbox" name="show_empty" value="1" <?php checked( $s['show_empty'] ?? false ); ?>>
						<span class="cf-toggle__track"></span>
					</label>
					<p class="description"><?php esc_html_e( 'Display taxonomy terms that have no associated products.', 'cf-plugin' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Show product count', 'cf-plugin' ); ?></th>
				<td>
					<label class="cf-toggle">
						<input type="checkbox" name="show_count" value="1" <?php checked( $s['show_count'] ?? true ); ?>>
						<span class="cf-toggle__track"></span>
					</label>
					<p class="description"><?php esc_html_e( 'Show number of matching products next to each term.', 'cf-plugin' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Show active filters bar', 'cf-plugin' ); ?></th>
				<td>
					<label class="cf-toggle">
						<input type="checkbox" name="show_active_filters" value="1" <?php checked( $s['show_active_filters'] ?? false ); ?>>
						<span class="cf-toggle__track"></span>
					</label>
					<p class="description"><?php esc_html_e( 'Show active filter chips. Use [active_filters] shortcode to control placement.', 'cf-plugin' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'AJAX filter', 'cf-plugin' ); ?></th>
				<td>
					<label class="cf-toggle">
						<input type="checkbox" name="ajax_filter" value="1" <?php checked( $s['ajax_filter'] ?? false ); ?>>
						<span class="cf-toggle__track"></span>
					</label>
					<p class="description"><?php esc_html_e( 'Update products without a full page reload.', 'cf-plugin' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Price currency symbol', 'cf-plugin' ); ?></th>
				<td>
					<input type="text"
						name="price_currency"
						id="cf-price-currency"
						value="<?php echo esc_attr( $s['price_currency'] ?? '' ); ?>"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'e.g. $ or € or leave empty for none', 'cf-plugin' ); ?>"
						maxlength="8">
					<p class="description">
						<?php esc_html_e( 'Symbol shown next to prices in the price filter. Leave empty to show numbers only.', 'cf-plugin' ); ?>
					</p>
				</td>
			</tr>

			<!-- ── Dropdown arrow SVG ───────────────────────────────── -->
			<tr>
				<th scope="row">
					<?php esc_html_e( 'Dropdown arrow icon', 'cf-plugin' ); ?>
				</th>
				<td>
					<div class="cf-svg-field">

						<!-- Live preview box -->
						<div class="cf-svg-preview" title="<?php esc_attr_e( 'Preview', 'cf-plugin' ); ?>">
							<span class="cf-svg-preview__icon">
								<?php
								// Show current saved SVG, or the default
								echo $current_svg ?: $default_arrow_svg;
								?>
							</span>
							<span class="cf-svg-preview__label">
								<?php esc_html_e( 'Preview', 'cf-plugin' ); ?>
							</span>
						</div>

						<textarea name="dropdown_arrow_svg"
						          id="cf-arrow-svg-input"
						          rows="4"
						          class="large-text code cf-svg-textarea"
						          placeholder="<?php echo esc_attr( $default_arrow_svg ); ?>"
						          spellcheck="false"><?php echo esc_textarea( $current_svg ); ?></textarea>

						<p class="description">
							<?php esc_html_e( 'Paste an inline SVG. Leave empty to use the default chevron. The icon inherits color via currentColor — set it with CSS.', 'cf-plugin' ); ?>
						</p>

						<button type="button" class="button button-small" id="cf-arrow-reset">
							<?php esc_html_e( 'Reset to default', 'cf-plugin' ); ?>
						</button>

					</div>

					<script>
					(function () {
						var textarea  = document.getElementById('cf-arrow-svg-input');
						var preview   = document.querySelector('.cf-svg-preview__icon');
						var resetBtn  = document.getElementById('cf-arrow-reset');
						var defaultSvg = <?php echo json_encode( $default_arrow_svg ); ?>;

						function updatePreview() {
							var val = textarea.value.trim();
							preview.innerHTML = val || defaultSvg;
						}

						textarea.addEventListener('input', updatePreview);

						resetBtn.addEventListener('click', function () {
							textarea.value = '';
							updatePreview();
						});
					}());
					</script>
				</td>
			</tr>

		</table>

		<div class="cf-settings-shortcode">
			<h3><?php esc_html_e( 'Shortcodes', 'cf-plugin' ); ?></h3>
			<p><code>[custom_filter]</code> — <?php esc_html_e( 'renders the filter form', 'cf-plugin' ); ?></p>
			<p><code>[active_filters]</code> — <?php esc_html_e( 'renders the active filter chips bar', 'cf-plugin' ); ?></p>
		</div>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'cf-plugin' ); ?></button>
		</p>
	</form>
	<?php
}