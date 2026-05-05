<?php
defined( 'ABSPATH' ) || exit;

require_once CF_PATH . 'admin/tabs/tab-taxonomies.php';
require_once CF_PATH . 'admin/tabs/tab-settings.php';

function cf_render_settings_page() {
	$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'taxonomies';

	$tabs = [
		'taxonomies' => __( 'Taxonomies', 'cf-plugin' ),
		'settings'   => __( 'Settings', 'cf-plugin' ),
	];
	?>
	<div class="wrap cf-admin-wrap">
		<h1><?php esc_html_e( 'Custom Filter CL', 'cf-plugin' ); ?></h1>

		<nav class="cf-admin-tabs nav-tab-wrapper">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cf-settings&tab=' . $slug ) ); ?>"
				   class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<div class="cf-admin-content">
			<?php
			switch ( $active_tab ) {
				case 'settings':
					cf_render_tab_settings();
					break;
				case 'taxonomies':
				default:
					cf_render_tab_taxonomies();
					break;
			}
			?>
		</div>
	</div>
	<?php
}