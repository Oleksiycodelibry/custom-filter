<?php
/**
 * Plugin Name: WC Filter
 * Description: Custom filter for WooCommerce
 * Version:     1.0.0
 * Author:      Codelibry
 * Author URI:  https://codelibry.com/
 * Text Domain: cf-plugin
 */

defined( 'ABSPATH' ) || exit;

define( 'CF_PATH', plugin_dir_path( __FILE__ ) );
define( 'CF_URL',  plugin_dir_url( __FILE__ ) );
define( 'CF_VERSION', '1.0.0' );

// Core 
require_once CF_PATH . 'inc/plugin-hooks.php';
require_once CF_PATH . 'admin/settings.php';

// Admin 
add_action( 'admin_menu', 'cf_settings_page' );

function cf_settings_page() {
	add_menu_page(
		__( 'Custom Filter', 'cf-plugin' ),
		__( 'Custom Filter', 'cf-plugin' ),
		'manage_options',
		'cf-settings',
		'cf_render_settings_page',
		'dashicons-filter',
		58
	);
}
