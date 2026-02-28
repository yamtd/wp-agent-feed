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
// the if-not-defined blocks (lines 17-25) are satisfied.
if ( ! defined( 'WAF_CACHE_DIR' ) ) {
	define( 'WAF_CACHE_DIR', sys_get_temp_dir() . '/waf-test-cache/' );
}
if ( ! defined( 'WAF_POST_TYPES' ) ) {
	define( 'WAF_POST_TYPES', [ 'post', 'page' ] );
}
if ( ! defined( 'WAF_CONTENT_SIGNAL' ) ) {
	define( 'WAF_CONTENT_SIGNAL', 'ai-train=no, search=yes, ai-input=yes' );
}

// Stub WordPress functions called at the top level of the plugin file.
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {} // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
}

// Load the plugin.
require_once dirname( __DIR__ ) . '/wp-agent-feed.php';
