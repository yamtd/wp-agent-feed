<?php
/**
 * Plugin Name: WP Agent Feed
 * Plugin URI: https://github.com/yamtd/wp-agent-feed
 * Description: Accept: text/markdown ヘッダー付きリクエストに対して、投稿コンテンツをMarkdownで返す。保存時に静的キャッシュを生成するパフォーマンス重視設計。
 * Version: 0.3.1
 * Requires PHP: 7.4
 * Author: Yamada Tadaaki
 * Author URI: https://github.com/yamtd
 * License: GPL-2.0-or-later
 * Text Domain: wp-agent-feed
 * Update URI: https://github.com/yamtd/wp-agent-feed
 */

namespace WpAgentFeed;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/markdown.php';

/* ========================================
 * 設定（優先順位: wp-config.php 定数 > DB オプション > デフォルト値）
 * ======================================== */
if ( ! defined( __NAMESPACE__ . '\CACHE_DIR' ) ) {
	define( __NAMESPACE__ . '\CACHE_DIR', WP_CONTENT_DIR . '/cache/markdown/' );
}
if ( ! defined( __NAMESPACE__ . '\POST_TYPES' ) ) {
	define(
		__NAMESPACE__ . '\POST_TYPES',
		false !== get_option( 'wp_agent_feed_post_types' )
			? (array) get_option( 'wp_agent_feed_post_types' )
			: array( 'post', 'page' )
	);
} else {
	is_overridden( 'POST_TYPES', true );
}
if ( ! defined( __NAMESPACE__ . '\CONTENT_SIGNAL' ) ) {
	define(
		__NAMESPACE__ . '\CONTENT_SIGNAL',
		get_option( 'wp_agent_feed_content_signal', 'ai-train=no, search=yes, ai-input=yes' )
	);
} else {
	is_overridden( 'CONTENT_SIGNAL', true );
}
if ( ! defined( __NAMESPACE__ . '\CACHE_CONTROL' ) ) {
	define(
		__NAMESPACE__ . '\CACHE_CONTROL',
		get_option( 'wp_agent_feed_cache_control', 'public, max-age=3600' )
	);
} else {
	is_overridden( 'CACHE_CONTROL', true );
}

register_uninstall_hook( __FILE__, 'WpAgentFeed\uninstall' );

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'wp-agent-feed', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

add_filter( 'robots_txt', __NAMESPACE__ . '\filter_robots_txt', 10, 2 );

/* ========================================
 * 1. 早期インターセプト — Accept ヘッダーの確認とキャッシュ配信
 * ======================================== */
add_action( 'wp', __NAMESPACE__ . '\serve_markdown', 1 );

function serve_markdown() {
	$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
	if ( strpos( $accept, 'text/markdown' ) === false ) {
		return;
	}

	if ( ! is_singular( POST_TYPES ) ) {
		return;
	}

	$post_id    = get_queried_object_id();
	$cache_path = cache_path( $post_id );

	if ( ! file_exists( $cache_path ) ) {
		generate_cache( $post_id );
	}

	if ( ! file_exists( $cache_path ) ) {
		return;
	}

	$markdown = file_get_contents( $cache_path );
	if ( false === $markdown ) {
		return;
	}
	$token_count = estimate_tokens( $markdown );

	status_header( 200 );
	header( 'Content-Type: text/markdown; charset=utf-8' );
	header( 'Vary: Accept' );
	header( 'X-Markdown-Tokens: ' . $token_count );
	header( 'Content-Signal: ' . CONTENT_SIGNAL );
	header( 'Content-Length: ' . strlen( $markdown ) );
	if ( CACHE_CONTROL !== '' ) {
		header( 'Cache-Control: ' . CACHE_CONTROL );
	}

	echo $markdown;
	exit;
}

/* ========================================
 * 2. 保存時キャッシュ生成
 * ======================================== */
add_action( 'save_post', __NAMESPACE__ . '\on_save_post', 20, 2 );

function on_save_post( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( ! in_array( $post->post_type, POST_TYPES, true ) ) {
		return;
	}

	if ( $post->post_status === 'publish' ) {
		generate_cache( $post_id );
	} else {
		delete_cache( $post_id );
	}
}

add_action( 'trashed_post', __NAMESPACE__ . '\maybe_delete_cache' );
add_action( 'deleted_post', __NAMESPACE__ . '\maybe_delete_cache' );

/* ========================================
 * 3. Markdown生成（コア処理）
 * ======================================== */
function generate_cache( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || $post->post_status !== 'publish' ) {
		return false;
	}

	if ( ! initialize_cache_dir() ) {
		return false;
	}

	$frontmatter = build_frontmatter( $post );
	$html        = get_rendered_content( $post );
	$markdown    = $frontmatter . html_to_markdown( $html );

	$dest = cache_path( $post_id );
	$tmp  = $dest . '.tmp';

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	if ( false === file_put_contents( $tmp, $markdown, LOCK_EX ) ) {
		// translators: %s is the temp file path.
		error_log( sprintf( 'WP Agent Feed: Failed to write temp cache %s', $tmp ) );
		return false;
	}

	// rename() is atomic on the same filesystem.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
	if ( ! rename( $tmp, $dest ) ) {
		// translators: %1$s is the temp path, %2$s is the destination path.
		error_log( sprintf( 'WP Agent Feed: Failed to rename %1$s to %2$s', $tmp, $dest ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $tmp );
		return false;
	}

	return true;
}

/**
 * キャッシュディレクトリの初期化（セキュリティ保護ファイル付き）。
 *
 * @return bool ディレクトリが利用可能なら true。
 */
function initialize_cache_dir() {
	if ( is_dir( CACHE_DIR ) ) {
		return true;
	}

	wp_mkdir_p( CACHE_DIR );

	if ( ! is_dir( CACHE_DIR ) ) {
		// translators: %s is the cache directory path.
		error_log( sprintf( 'WP Agent Feed: Failed to create cache directory %s', CACHE_DIR ) );
		return false;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	if ( false === file_put_contents( CACHE_DIR . '.htaccess', "# Apache 2.4+\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n\n# Apache 2.2\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n" ) ) {
		// translators: %s is the cache directory path.
		error_log( sprintf( 'WP Agent Feed: Failed to create .htaccess in %s', CACHE_DIR ) );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	if ( false === file_put_contents( CACHE_DIR . 'index.html', '' ) ) {
		// translators: %s is the cache directory path.
		error_log( sprintf( 'WP Agent Feed: Failed to create index.html in %s', CACHE_DIR ) );
	}

	return true;
}

/**
 * the_content フィルターを安全に適用。
 */
function get_rendered_content( $post ) {
	$prev_post       = $GLOBALS['post'] ?? null;
	$GLOBALS['post'] = $post;
	setup_postdata( $post );

	$html = apply_filters( 'the_content', $post->post_content );

	if ( $prev_post ) {
		$GLOBALS['post'] = $prev_post;
		setup_postdata( $prev_post );
	} else {
		wp_reset_postdata();
	}

	return $html;
}

/* ========================================
 * 4. フロントマター生成（Cloudflare互換）
 * ======================================== */
function build_frontmatter( $post ) {
	$title       = $post->post_title;
	$description = get_the_excerpt( $post );
	$url         = get_permalink( $post );
	$date        = get_the_date( 'Y-m-d', $post );
	$modified    = get_the_modified_date( 'Y-m-d', $post );
	$image       = get_the_post_thumbnail_url( $post, 'full' ) ?: '';

	$lines = [
		'---',
		'title: "' . escape_yaml( $title ) . '"',
		'description: "' . escape_yaml( $description ) . '"',
		'url: "' . escape_yaml( $url ) . '"',
		'date: ' . $date,
		'modified: ' . $modified,
	];

	if ( $image ) {
		$lines[] = 'image: "' . escape_yaml( $image ) . '"';
	}

	$lines[] = '---';
	$lines[] = '';

	return implode( "\n", $lines );
}

/* ========================================
 * ユーティリティ
 * ======================================== */

/**
 * 設定値が wp-config.php 定数でオーバーライドされているか判定。
 *
 * @param string $name  設定名（'CONTENT_SIGNAL' or 'POST_TYPES'）。
 * @param bool   $mark  true の場合、オーバーライドとして登録。
 * @param bool   $reset true の場合、全登録をクリア（テスト専用）。
 * @return bool オーバーライドされていれば true。
 */
function is_overridden( $name = '', $mark = false, $reset = false ) {
	static $overrides = array();
	if ( $reset ) {
		$overrides = array();
		return false;
	}
	if ( $mark && $name ) {
		$overrides[ $name ] = true;
	}
	return isset( $overrides[ $name ] );
}

/**
 * CACHE_DIR の URL 相対パスを返す。Web 非公開の場合は空文字列。
 *
 * @param string $abspath   WordPress ルート絶対パス（末尾スラッシュ付き）。
 * @param string $cache_dir キャッシュディレクトリ絶対パス。
 * @return string URL 相対パス（例: /wp-content/cache/markdown/）または空文字列。
 */
function robots_disallow_path( $abspath, $cache_dir ) {
	if ( 0 !== strpos( $cache_dir, $abspath ) ) {
		return '';
	}
	$relative = substr( $cache_dir, strlen( $abspath ) );
	if ( '' === $relative || false === $relative ) {
		return '';
	}
	return '/' . $relative;
}

/**
 * robots.txt に Disallow ルールを追加するフィルターコールバック。
 *
 * @param string $output robots.txt の出力文字列。
 * @param int    $public サイトの公開設定。
 * @return string フィルター済み出力。
 */
function filter_robots_txt( $output, $public ) {
	$path = robots_disallow_path( ABSPATH, CACHE_DIR );
	if ( '' === $path ) {
		return $output;
	}

	$rule = 'Disallow: ' . $path;
	if ( preg_match( '/^' . preg_quote( $rule, '/' ) . '\r?$/m', $output ) ) {
		return $output;
	}

	$output .= "\n" . $rule . "\n";
	return $output;
}

function cache_path( $post_id ) {
	return CACHE_DIR . intval( $post_id ) . '.md';
}

function delete_cache( $post_id ) {
	$path = cache_path( $post_id );
	if ( file_exists( $path ) ) {
		unlink( $path );
	}
}

function maybe_delete_cache( $post_id ) {
	$post_type = get_post_type( $post_id );
	if ( $post_type && in_array( $post_type, POST_TYPES, true ) ) {
		delete_cache( $post_id );
	}
}

/**
 * キャッシュディレクトリ内の数値名 .md ファイル数を返す。
 *
 * @param string $cache_dir キャッシュディレクトリのパス（末尾スラッシュ付き）。
 * @return int ファイル数。
 */
function get_cache_stats( $cache_dir ) {
	if ( ! is_dir( $cache_dir ) ) {
		return 0;
	}

	$files = glob( $cache_dir . '*.md' );
	if ( ! is_array( $files ) ) {
		return 0;
	}

	$count = 0;
	foreach ( $files as $file ) {
		if ( preg_match( '/^\d+\.md$/', basename( $file ) ) ) {
			++$count;
		}
	}

	return $count;
}

/**
 * キャッシュファイルの Markdown 出力を検証する。
 *
 * @param string $body           キャッシュファイルの内容。
 * @param string $content_signal Content-Signal 設定値。
 * @return array { pass: bool, checks: array[] }
 */
function validate_markdown_output( $body, $content_signal ) {
	$checks = array();

	// 1. Frontmatter.
	$has_fm   = 0 === strpos( $body, "---\n" ) || 0 === strpos( $body, "---\r\n" );
	$checks[] = array(
		'name'   => 'frontmatter',
		'pass'   => $has_fm,
		'expect' => 'starts with ---',
		'actual' => $has_fm ? 'starts with ---' : '(no frontmatter)',
	);

	// 2. Body not empty.
	$len      = strlen( $body );
	$checks[] = array(
		'name'   => 'body_not_empty',
		'pass'   => $len > 0,
		'expect' => '> 0 bytes',
		'actual' => $len . ' bytes',
	);

	// 3. Token estimate.
	$tokens   = estimate_tokens( $body );
	$checks[] = array(
		'name'   => 'token_estimate',
		'pass'   => $tokens > 0,
		'expect' => '> 0',
		'actual' => (string) $tokens,
	);

	// 4. Content-Signal configured.
	$checks[] = array(
		'name'   => 'content_signal',
		'pass'   => '' !== $content_signal,
		'expect' => 'non-empty',
		'actual' => '' !== $content_signal ? $content_signal : '(empty)',
	);

	$pass = true;
	foreach ( $checks as $check ) {
		if ( ! $check['pass'] ) {
			$pass = false;
			break;
		}
	}

	return array(
		'pass'   => $pass,
		'checks' => $checks,
	);
}

/* ========================================
 * WP-CLI: キャッシュ一括再生成
 * 使い方: wp markdown-cache regenerate
 * ======================================== */
if ( defined( 'WP_CLI' ) && \WP_CLI ) {
	\WP_CLI::add_command(
		'markdown-cache',
		function ( $args, $assoc_args ) {
			if ( empty( $args[0] ) || $args[0] !== 'regenerate' ) {
				\WP_CLI::error( 'Usage: wp markdown-cache regenerate' );
			}

			$posts = get_posts(
				[
					'post_type'      => POST_TYPES,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				]
			);

			$count = count( $posts );
			\WP_CLI::log( "Regenerating markdown cache for {$count} posts..." );

			foreach ( $posts as $i => $post_id ) {
				generate_cache( $post_id );
				\WP_CLI::log( sprintf( '  [%d/%d] Post #%d', $i + 1, $count, $post_id ) );
			}

			\WP_CLI::success( "Done. {$count} cache files generated in " . CACHE_DIR );
		}
	);
}

/* ========================================
 * Admin: 設定ページ（is_admin() 時のみロード）
 * ======================================== */
if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), __NAMESPACE__ . '\add_settings_link' );

/**
 * プラグイン一覧に設定ページへのリンクを追加。
 *
 * @param array $links Existing action links.
 * @return array Modified action links.
 */
function add_settings_link( $links ) {
	$url           = admin_url( 'options-general.php?page=wp-agent-feed' );
	$settings_link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'wp-agent-feed' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}

/**
 * キャッシュディレクトリ内の全 .md ファイルを削除。
 *
 * @return int 削除したファイル数。
 */
function clear_all_cache() {
	if ( ! is_dir( CACHE_DIR ) ) {
		return 0;
	}

	$files = glob( CACHE_DIR . '*.md' );
	if ( ! is_array( $files ) ) {
		return 0;
	}

	$count = 0;
	foreach ( $files as $file ) {
		if ( ! preg_match( '/^\d+\.md$/', basename( $file ) ) ) {
			continue;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		if ( unlink( $file ) ) {
			++$count;
		}
	}

	return $count;
}
/* ========================================
 * GitHub 自動更新（WP 5.8+）
 * ======================================== */
add_filter( 'update_plugins_github.com', __NAMESPACE__ . '\check_github_update', 10, 4 );

/**
 * GitHub Releases から最新バージョンを確認し、更新情報を返す。
 *
 * WP 5.8+ の Update URI 機構を利用。5.8 未満ではフィルターが存在しないため無視される。
 *
 * @param array|false $update     The plugin update data.
 * @param array       $plugin_data Plugin headers (Version, UpdateURI, etc.).
 * @param string      $plugin_file Plugin file path relative to plugins dir.
 * @param string[]    $locales     Installed locales.
 * @return array|false Update data or false if no update available.
 */
function check_github_update( $update, $plugin_data, $plugin_file, $locales ) {
	if ( plugin_basename( __FILE__ ) !== $plugin_file ) {
		return $update;
	}

	if ( ! empty( $update ) ) {
		return $update;
	}

	$cached = get_transient( 'wp_agent_feed_gh_update' );

	if ( false !== $cached ) {
		if ( isset( $cached['no_update'] ) ) {
			return false;
		}

		// 更新済みならキャッシュを破棄。
		if ( ! version_compare( $cached['version'], $plugin_data['Version'], '>' ) ) {
			delete_transient( 'wp_agent_feed_gh_update' );
			return false;
		}

		return array(
			'slug'    => 'wp-agent-feed',
			'version' => $cached['version'],
			'url'     => $cached['url'],
			'package' => $cached['package'],
		);
	}

	$response = wp_remote_get(
		'https://api.github.com/repos/yamtd/wp-agent-feed/releases/latest',
		array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'WordPress/wp-agent-feed',
			),
		)
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return false;
	}

	$release = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
		return false;
	}

	$remote_version = ltrim( $release['tag_name'], 'v' );

	// ZIP アセットを検索。
	$zip_url = '';
	if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
		foreach ( $release['assets'] as $asset ) {
			if ( ! empty( $asset['browser_download_url'] ) && '.zip' === substr( $asset['browser_download_url'], -4 ) ) {
				$zip_url = $asset['browser_download_url'];
				break;
			}
		}
	}

	if ( '' === $zip_url ) {
		return false;
	}

	if ( ! version_compare( $remote_version, $plugin_data['Version'], '>' ) ) {
		set_transient( 'wp_agent_feed_gh_update', array( 'no_update' => true ), 12 * HOUR_IN_SECONDS );
		return false;
	}

	$data = array(
		'version' => $remote_version,
		'url'     => $release['html_url'],
		'package' => $zip_url,
	);

	set_transient( 'wp_agent_feed_gh_update', $data, 12 * HOUR_IN_SECONDS );

	return array(
		'slug'    => 'wp-agent-feed',
		'version' => $data['version'],
		'url'     => $data['url'],
		'package' => $data['package'],
	);
}

/* ========================================
 * アンインストール処理
 * ======================================== */

/**
 * プラグイン削除時のクリーンアップ。
 *
 * キャッシュディレクトリ内のプラグイン所有ファイルとオプションを削除。
 */
function uninstall() {
	$cache_dir = defined( 'WpAgentFeed\CACHE_DIR' ) ? CACHE_DIR : WP_CONTENT_DIR . '/cache/markdown/';

	if ( is_dir( $cache_dir ) ) {
		// *.md ファイル（投稿ID形式のみ）。
		$md_files = glob( $cache_dir . '*.md' );
		if ( is_array( $md_files ) ) {
			foreach ( $md_files as $file ) {
				if ( preg_match( '/^\d+\.md$/', basename( $file ) ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					unlink( $file );
				}
			}
		}

		// .htaccess（プラグイン生成テンプレートと一致する場合のみ）。
		$htaccess = $cache_dir . '.htaccess';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( is_file( $htaccess ) && file_get_contents( $htaccess ) === "# Apache 2.4+\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n\n# Apache 2.2\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n" ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $htaccess );
		}

		// index.html（空ファイルの場合のみ）。
		$index = $cache_dir . 'index.html';
		if ( is_file( $index ) && 0 === filesize( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $index );
		}

		// ディレクトリが空の場合のみ削除。
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $cache_dir );
	}

	delete_option( 'wp_agent_feed_content_signal' );
	delete_option( 'wp_agent_feed_post_types' );
	delete_option( 'wp_agent_feed_cache_control' );
	delete_transient( 'wp_agent_feed_gh_update' );
}
