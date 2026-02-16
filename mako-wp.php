<?php
/**
 * Plugin Name:       MAKO - AI-Optimized Content
 * Plugin URI:        https://makospec.vercel.app
 * Description:       Serve LLM-optimized markdown content via HTTP content negotiation. Reduces token consumption by ~94% while preserving semantic meaning.
 * Version:           1.5.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            MAKO Protocol
 * Author URI:        https://makospec.vercel.app
 * License:           Apache-2.0
 * License URI:       https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain:       mako-wp
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MAKO_VERSION', '1.5.0' );
define( 'MAKO_SPEC_VERSION', '1.0' );
define( 'MAKO_PLUGIN_FILE', __FILE__ );
define( 'MAKO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAKO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MAKO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once MAKO_PLUGIN_DIR . 'includes/class-mako-plugin.php';

register_activation_hook( __FILE__, array( 'Mako_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Mako_Deactivator', 'deactivate' ) );

Mako_Plugin::instance();
