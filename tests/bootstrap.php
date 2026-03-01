<?php
/**
 * PHPUnit bootstrap file.
 *
 * Defines WordPress stubs so the plugin file can be loaded
 * without a full WordPress environment.
 */

// WordPress constants required by the plugin's ABSPATH guard (line 12).
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', '/tmp/wordpress/wp-content' );
}

// Plugin constants — define before loading the plugin so
// the if-not-defined blocks are satisfied.
if ( ! defined( 'WpAgentFeed\CACHE_DIR' ) ) {
	define( 'WpAgentFeed\CACHE_DIR', sys_get_temp_dir() . '/waf-test-cache/' );
}
if ( ! defined( 'WpAgentFeed\POST_TYPES' ) ) {
	define( 'WpAgentFeed\POST_TYPES', [ 'post', 'page' ] );
}
if ( ! defined( 'WpAgentFeed\CONTENT_SIGNAL' ) ) {
	define( 'WpAgentFeed\CONTENT_SIGNAL', 'ai-train=no, search=yes, ai-input=yes' );
}

// Stub WordPress functions called at the top level of the plugin file.
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {} // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
}
if ( ! function_exists( 'register_uninstall_hook' ) ) {
	function register_uninstall_hook() {} // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {} // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
}
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); } // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) { return $default; } // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
}

// Load the plugin.
require_once dirname( __DIR__ ) . '/wp-agent-feed.php';
