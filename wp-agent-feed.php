<?php
/**
 * Plugin Name: WP Agent Feed
 * Plugin URI: https://github.com/yamtd/wp-agent-feed
 * Description: Accept: text/markdown ヘッダー付きリクエストに対して、投稿コンテンツをMarkdownで返す。保存時に静的キャッシュを生成するパフォーマンス重視設計。
 * Version: 0.3.0
 * Requires PHP: 7.4
 * Author: Yamada Tadaaki
 * Author URI: https://github.com/yamtd
 * License: GPL-2.0-or-later
 * Text Domain: wp-agent-feed
 * Update URI: https://github.com/yamtd/wp-agent-feed
 */

namespace WpAgentFeed;

defined( 'ABSPATH' ) || exit;

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
 * 5. HTML → Markdown 変換（軽量・依存なし）
 * ======================================== */
function html_to_markdown( $html ) {
	$md           = trim( $html );
	$placeholders = array();

	$md = convert_code_blocks( $md, $placeholders );
	$md = convert_headings( $md );
	$md = convert_tables( $md );
	$md = convert_blockquotes( $md );
	$md = convert_lists( $md );
	$md = convert_media( $md );
	$md = convert_links( $md );
	$md = convert_inline( $md );
	$md = cleanup_markdown( $md );

	// コードブロックを復元
	if ( $placeholders ) {
		$md = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $md );
	}

	return trim( $md ) . "\n";
}

function convert_headings( $md ) {
	for ( $i = 6; $i >= 1; $i-- ) {
		$prefix = str_repeat( '#', $i );
		$md     = preg_replace(
			'/<h' . $i . '[^>]*>(.*?)<\/h' . $i . '>/si',
			"\n" . $prefix . ' $1' . "\n",
			$md
		);
	}
	return $md;
}

function convert_code_blocks( $md, &$placeholders ) {
	// <pre><code> ブロック（言語クラス対応）→ プレースホルダーに退避
	$md = preg_replace_callback(
		'/<pre[^>]*>\s*<code[^>]*?(?:class=["\'](?:language-)?(\w+)["\'][^>]*)?>(.*?)<\/code>\s*<\/pre>/si',
		function ( $m ) use ( &$placeholders ) {
			$lang                 = $m[1] ?? '';
			$code                 = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
			$fenced               = "\n```" . $lang . "\n" . $code . "\n```\n";
			$key                  = '__CODE_BLOCK_' . count( $placeholders ) . '__';
			$placeholders[ $key ] = $fenced;
			return $key;
		},
		$md
	);

	// <pre> 単体 → プレースホルダーに退避
	$md = preg_replace_callback(
		'/<pre[^>]*>(.*?)<\/pre>/si',
		function ( $m ) use ( &$placeholders ) {
			$code                 = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
			$fenced               = "\n```\n" . $code . "\n```\n";
			$key                  = '__CODE_BLOCK_' . count( $placeholders ) . '__';
			$placeholders[ $key ] = $fenced;
			return $key;
		},
		$md
	);

	return $md;
}

function convert_tables( $md ) {
	return preg_replace_callback(
		'/<table[^>]*>(.*?)<\/table>/si',
		__NAMESPACE__ . '\convert_table',
		$md
	);
}

function convert_blockquotes( $md ) {
	return preg_replace_callback(
		'/<blockquote[^>]*>(.*?)<\/blockquote>/si',
		function ( $m ) {
			$inner = html_to_markdown( trim( $m[1] ) );
			$lines = explode( "\n", trim( $inner ) );
			return "\n" . implode( "\n", array_map( fn( $l ) => '> ' . $l, $lines ) ) . "\n";
		},
		$md
	);
}

function convert_lists( $md ) {
	// 順序なしリスト
	$md = preg_replace_callback(
		'/<ul[^>]*>(.*?)<\/ul>/si',
		function ( $m ) {
			return "\n" . preg_replace( '/<li[^>]*>(.*?)<\/li>/si', "- $1\n", $m[1] ) . "\n";
		},
		$md
	);

	// 順序付きリスト（start 属性対応）
	$md = preg_replace_callback(
		'/<ol([^>]*)>(.*?)<\/ol>/si',
		function ( $m ) {
			$start = 1;
			if ( preg_match( '/start=["\']?(\d+)["\']?/i', $m[1], $sm ) ) {
				$start = (int) $sm[1];
			}
			$i = $start - 1;
			return "\n" . preg_replace_callback(
				'/<li[^>]*>(.*?)<\/li>/si',
				function ( $lm ) use ( &$i ) {
					$i++;
					return $i . '. ' . $lm[1] . "\n";
				},
				$m[2]
			) . "\n";
		},
		$md
	);

	return $md;
}

function convert_media( $md ) {
	// 画像（属性順序に依存しない統合パターン）
	$md = preg_replace_callback(
		'/<img[^>]*\/?>/si',
		function ( $m ) {
			$tag = $m[0];
			$src = '';
			$alt = '';
			if ( preg_match( '/src=["\']([^"\']+)["\']/', $tag, $sm ) ) {
				$src = $sm[1];
			}
			if ( preg_match( '/alt=["\']([^"\']*)["\']/', $tag, $am ) ) {
				$alt = $am[1];
			}
			return $src ? '![' . $alt . '](' . $src . ')' : '';
		},
		$md
	);

	// figure / figcaption
	$md = preg_replace( '/<\/?figure[^>]*>/si', "\n", $md );
	$md = preg_replace( '/<figcaption[^>]*>(.*?)<\/figcaption>/si', '*$1*', $md );

	return $md;
}

function convert_links( $md ) {
	return preg_replace(
		'/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si',
		'[$2]($1)',
		$md
	);
}

function convert_inline( $md ) {
	$map = [
		'strong' => '**',
		'b'      => '**',
		'em'     => '*',
		'i'      => '*',
		'code'   => '`',
		'del'    => '~~',
	];

	foreach ( $map as $tag => $wrap ) {
		$md = preg_replace( "/<{$tag}[^>]*>(.*?)<\/{$tag}>/si", "{$wrap}\$1{$wrap}", $md );
	}

	return $md;
}

function cleanup_markdown( $md ) {
	// 段落
	$md = preg_replace( '/<p[^>]*>/si', "\n", $md );
	$md = str_replace( '</p>', "\n", $md );

	// <br>
	$md = preg_replace( '/<br\s*\/?>/si', "  \n", $md );

	// <hr>
	$md = preg_replace( '/<hr[^>]*\/?>/si', "\n---\n", $md );

	// 残りのHTMLタグを除去
	$md = strip_tags( $md );

	// HTMLエンティティをデコード
	$md = html_entity_decode( $md, ENT_QUOTES, 'UTF-8' );

	// 連続空行を正規化
	$md = preg_replace( '/\n{3,}/', "\n\n", $md );

	return $md;
}

/**
 * HTML <table> → Markdown テーブル
 */
function convert_table( $matches ) {
	$table_html = $matches[1];
	$rows       = [];

	preg_match_all( '/<tr[^>]*>(.*?)<\/tr>/si', $table_html, $tr_matches );

	if ( empty( $tr_matches[1] ) ) {
		return $matches[0];
	}

	foreach ( $tr_matches[1] as $tr ) {
		$cells = [];
		preg_match_all( '/<(?:td|th)[^>]*>(.*?)<\/(?:td|th)>/si', $tr, $cell_matches );
		foreach ( $cell_matches[1] as $cell ) {
			$cells[] = str_replace( '|', '\|', trim( strip_tags( $cell ) ) );
		}
		$rows[] = $cells;
	}

	if ( empty( $rows ) ) {
		return $matches[0];
	}

	$col_count = count( $rows[0] );
	$output    = "\n";
	$output   .= '| ' . implode( ' | ', $rows[0] ) . " |\n";
	$output   .= '| ' . implode( ' | ', array_fill( 0, $col_count, '---' ) ) . " |\n";

	for ( $i = 1, $len = count( $rows ); $i < $len; $i++ ) {
		$row     = array_pad( $rows[ $i ], $col_count, '' );
		$output .= '| ' . implode( ' | ', $row ) . " |\n";
	}

	return $output;
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

function escape_yaml( $str ) {
	return str_replace( [ '\\', '"', "\n", "\r" ], [ '\\\\', '\\"', ' ', '' ], $str );
}

/**
 * トークン数の推定（英語: ~4文字/token、日本語: ~1.5文字/token の加重平均）
 */
function estimate_tokens( $text ) {
	$bytes = strlen( $text );
	$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : $bytes;

	$mb_ratio        = ( $bytes > 0 ) ? 1 - ( $len / $bytes ) : 0;
	$chars_per_token = 4 - ( $mb_ratio * 2.5 );
	$chars_per_token = max( $chars_per_token, 1.5 );

	return (int) ceil( $len / $chars_per_token );
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
 * Admin: 設定ページ
 * ======================================== */
add_action( 'admin_menu', __NAMESPACE__ . '\add_admin_menu' );
add_action( 'admin_init', __NAMESPACE__ . '\register_settings' );
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
 * 管理メニューに設定ページを追加。
 */
function add_admin_menu() {
	add_options_page(
		__( 'WP Agent Feed', 'wp-agent-feed' ),
		__( 'WP Agent Feed', 'wp-agent-feed' ),
		'manage_options',
		'wp-agent-feed',
		__NAMESPACE__ . '\render_settings_page'
	);
}

/**
 * Settings API にオプションとフィールドを登録。
 */
function register_settings() {
	$has_editable = false;

	if ( ! is_overridden( 'CONTENT_SIGNAL' ) ) {
		register_setting(
			'wp_agent_feed_settings',
			'wp_agent_feed_content_signal',
			array(
				'type'              => 'string',
				'sanitize_callback' => __NAMESPACE__ . '\sanitize_content_signal',
				'default'           => 'ai-train=no, search=yes, ai-input=yes',
			)
		);
		$has_editable = true;
	}

	if ( ! is_overridden( 'CACHE_CONTROL' ) ) {
		register_setting(
			'wp_agent_feed_settings',
			'wp_agent_feed_cache_control',
			array(
				'type'              => 'string',
				'sanitize_callback' => __NAMESPACE__ . '\sanitize_cache_control',
				'default'           => 'public, max-age=3600',
			)
		);
		$has_editable = true;
	}

	if ( ! is_overridden( 'POST_TYPES' ) ) {
		register_setting(
			'wp_agent_feed_settings',
			'wp_agent_feed_post_types',
			array(
				'type'              => 'array',
				'sanitize_callback' => __NAMESPACE__ . '\sanitize_post_types',
				'default'           => array( 'post', 'page' ),
			)
		);
		$has_editable = true;
	}

	add_settings_section(
		'wp_agent_feed_general',
		__( 'General Settings', 'wp-agent-feed' ),
		__NAMESPACE__ . '\render_section_general',
		'wp-agent-feed'
	);

	add_settings_field(
		'wp_agent_feed_post_types',
		__( 'Post Types', 'wp-agent-feed' ),
		__NAMESPACE__ . '\render_field_post_types',
		'wp-agent-feed',
		'wp_agent_feed_general'
	);

	add_settings_field(
		'wp_agent_feed_content_signal',
		__( 'Content-Signal', 'wp-agent-feed' ),
		__NAMESPACE__ . '\render_field_content_signal',
		'wp-agent-feed',
		'wp_agent_feed_general'
	);

	add_settings_field(
		'wp_agent_feed_cache_control',
		__( 'Cache-Control', 'wp-agent-feed' ),
		__NAMESPACE__ . '\render_field_cache_control',
		'wp-agent-feed',
		'wp_agent_feed_general'
	);
}

/**
 * Content-Signal 値のサニタイズ。
 *
 * @param string $value Raw input.
 * @return string Sanitized value.
 */
function sanitize_content_signal( $value ) {
	return sanitize_text_field( $value );
}

/**
 * Cache-Control 値のサニタイズ。
 *
 * @param string $value Raw input.
 * @return string Sanitized value (empty string disables the header).
 */
function sanitize_cache_control( $value ) {
	$value = sanitize_text_field( $value );
	return str_replace( array( "\r", "\n" ), '', $value );
}

/**
 * Post Types 値のサニタイズ。
 *
 * @param mixed $value Raw input.
 * @return array Sanitized array of post type slugs.
 */
function sanitize_post_types( $value ) {
	if ( ! is_array( $value ) || empty( $value ) ) {
		return array( 'post', 'page' );
	}
	$sanitized = array_values(
		array_filter(
			array_map( 'sanitize_key', $value ),
			function ( $slug ) {
				return post_type_exists( $slug );
			}
		)
	);
	return empty( $sanitized ) ? array( 'post', 'page' ) : $sanitized;
}

/**
 * General セクションの説明テキスト。
 */
function render_section_general() {
	echo '<p>';
	esc_html_e(
		'Configure how WP Agent Feed serves Markdown responses. Settings defined as constants in wp-config.php take priority over these values.',
		'wp-agent-feed'
	);
	echo '</p>';
}

/**
 * Content-Signal テキストフィールドの描画。
 */
function render_field_content_signal() {
	if ( is_overridden( 'CONTENT_SIGNAL' ) ) {
		echo '<code>' . esc_html( CONTENT_SIGNAL ) . '</code>';
		echo '<p class="description">';
		esc_html_e(
			'Defined in wp-config.php. Remove the constant to manage this setting here.',
			'wp-agent-feed'
		);
		echo '</p>';
		return;
	}

	$value = get_option( 'wp_agent_feed_content_signal', 'ai-train=no, search=yes, ai-input=yes' );
	printf(
		'<input type="text" id="wp_agent_feed_content_signal" name="wp_agent_feed_content_signal" value="%s" class="regular-text" />',
		esc_attr( $value )
	);
	echo '<p class="description">';
	esc_html_e( 'The Content-Signal header value sent with Markdown responses.', 'wp-agent-feed' );
	echo '</p>';
}

/**
 * Cache-Control テキストフィールドの描画。
 */
function render_field_cache_control() {
	if ( is_overridden( 'CACHE_CONTROL' ) ) {
		echo '<code>' . esc_html( CACHE_CONTROL ) . '</code>';
		echo '<p class="description">';
		esc_html_e(
			'Defined in wp-config.php. Remove the constant to manage this setting here.',
			'wp-agent-feed'
		);
		echo '</p>';
		return;
	}

	$value = get_option( 'wp_agent_feed_cache_control', 'public, max-age=3600' );
	printf(
		'<input type="text" id="wp_agent_feed_cache_control" name="wp_agent_feed_cache_control" value="%s" class="regular-text" />',
		esc_attr( $value )
	);
	echo '<p class="description">';
	esc_html_e( 'The Cache-Control header value sent with Markdown responses. Leave empty to not send this header.', 'wp-agent-feed' );
	echo '</p>';
}

/**
 * Post Types チェックボックスの描画。
 */
function render_field_post_types() {
	if ( is_overridden( 'POST_TYPES' ) ) {
		echo '<code>' . esc_html( implode( ', ', POST_TYPES ) ) . '</code>';
		echo '<p class="description">';
		esc_html_e(
			'Defined in wp-config.php. Remove the constant to manage this setting here.',
			'wp-agent-feed'
		);
		echo '</p>';
		return;
	}

	$current = get_option( 'wp_agent_feed_post_types', array( 'post', 'page' ) );
	if ( ! is_array( $current ) ) {
		$current = array( 'post', 'page' );
	}

	$post_types = get_post_types( array( 'public' => true ), 'objects' );

	foreach ( $post_types as $pt ) {
		printf(
			'<label style="display: block; margin-bottom: 4px;"><input type="checkbox" name="wp_agent_feed_post_types[]" value="%s" %s /> %s (<code>%s</code>)</label>',
			esc_attr( $pt->name ),
			checked( in_array( $pt->name, $current, true ), true, false ),
			esc_html( $pt->labels->name ),
			esc_html( $pt->name )
		);
	}
}

/**
 * ステータスパネル用の診断データを収集。
 *
 * @return array { cache_dir_exists, cache_dir_writable, htaccess_exists, cached_count, total_count, cache_dir }
 */
function get_diagnostics_data() {
	$cached = get_cache_stats( CACHE_DIR );

	$total = 0;
	foreach ( POST_TYPES as $pt ) {
		$counts = wp_count_posts( $pt );
		if ( isset( $counts->publish ) ) {
			$total += (int) $counts->publish;
		}
	}

	return array(
		'cache_dir_exists'   => is_dir( CACHE_DIR ),
		'cache_dir_writable' => is_dir( CACHE_DIR ) && wp_is_writable( CACHE_DIR ),
		'htaccess_exists'    => file_exists( CACHE_DIR . '.htaccess' ),
		'cached_count'       => $cached,
		'total_count'        => $total,
		'cache_dir'          => CACHE_DIR,
	);
}

/**
 * ステータス & 診断パネルの HTML を描画。
 */
function render_status_panel() {
	$data = get_diagnostics_data();

	// Cache directory status.
	if ( $data['cache_dir_writable'] ) {
		$dir_icon  = 'dashicons-yes-alt';
		$dir_color = '#00a32a';
		$dir_text  = __( 'Writable', 'wp-agent-feed' );
	} elseif ( $data['cache_dir_exists'] ) {
		$dir_icon  = 'dashicons-dismiss';
		$dir_color = '#d63638';
		$dir_text  = __( 'Not writable', 'wp-agent-feed' );
	} else {
		$dir_icon  = 'dashicons-dismiss';
		$dir_color = '#d63638';
		$dir_text  = __( 'Does not exist', 'wp-agent-feed' );
	}

	// .htaccess status.
	if ( $data['htaccess_exists'] ) {
		$ht_icon  = 'dashicons-yes-alt';
		$ht_color = '#00a32a';
		$ht_text  = __( 'Present', 'wp-agent-feed' );
	} else {
		$ht_icon  = 'dashicons-dismiss';
		$ht_color = '#d63638';
		$ht_text  = __( 'Missing', 'wp-agent-feed' );
	}

	// Cache coverage status.
	$cached = $data['cached_count'];
	$total  = $data['total_count'];

	if ( 0 === $total ) {
		$cov_icon  = 'dashicons-minus';
		$cov_color = '#787c82';
		$cov_text  = __( 'No published posts', 'wp-agent-feed' );
	} elseif ( $cached >= $total ) {
		$cov_icon  = 'dashicons-yes-alt';
		$cov_color = '#00a32a';
		/* translators: 1: cached count, 2: total count */
		$cov_text = sprintf( __( '%1$d / %2$d posts cached', 'wp-agent-feed' ), min( $cached, $total ), $total );
	} elseif ( $cached > 0 ) {
		$cov_icon  = 'dashicons-warning';
		$cov_color = '#dba617';
		/* translators: 1: cached count, 2: total count */
		$cov_text = sprintf( __( '%1$d / %2$d posts cached', 'wp-agent-feed' ), $cached, $total );
	} else {
		$cov_icon  = 'dashicons-dismiss';
		$cov_color = '#d63638';
		/* translators: 1: cached count, 2: total count */
		$cov_text = sprintf( __( '%1$d / %2$d posts cached', 'wp-agent-feed' ), $cached, $total );
	}
	?>
	<h2><?php esc_html_e( 'Status', 'wp-agent-feed' ); ?></h2>
	<table class="widefat striped" style="max-width: 600px;">
		<tbody>
			<tr>
				<td style="width: 24px;"><span class="dashicons <?php echo esc_attr( $dir_icon ); ?>" style="color: <?php echo esc_attr( $dir_color ); ?>;"></span></td>
				<td><?php esc_html_e( 'Cache directory', 'wp-agent-feed' ); ?></td>
				<td><?php echo esc_html( $dir_text ); ?></td>
			</tr>
			<tr>
				<td><span class="dashicons <?php echo esc_attr( $ht_icon ); ?>" style="color: <?php echo esc_attr( $ht_color ); ?>;"></span></td>
				<td><?php esc_html_e( '.htaccess protection', 'wp-agent-feed' ); ?></td>
				<td><?php echo esc_html( $ht_text ); ?></td>
			</tr>
			<tr>
				<td><span class="dashicons <?php echo esc_attr( $cov_icon ); ?>" style="color: <?php echo esc_attr( $cov_color ); ?>;"></span></td>
				<td><?php esc_html_e( 'Cache coverage', 'wp-agent-feed' ); ?></td>
				<td><?php echo esc_html( $cov_text ); ?></td>
			</tr>
		</tbody>
	</table>
	<p style="margin-top: 12px;">
		<button type="button" class="button" id="wp-agent-feed-live-test">
			<?php esc_html_e( 'Verify Output', 'wp-agent-feed' ); ?>
		</button>
		<span id="wp-agent-feed-live-test-status" style="margin-left: 8px;"></span>
	</p>
	<div id="wp-agent-feed-live-test-result" style="display: none; margin-top: 12px;"></div>
	<p style="margin-top: 12px;">
		<button type="button" class="button" id="wp-agent-feed-check-headers">
			<?php esc_html_e( 'Check HTTP Headers', 'wp-agent-feed' ); ?>
		</button>
		<span id="wp-agent-feed-check-headers-status" style="margin-left: 8px;"></span>
	</p>
	<div id="wp-agent-feed-check-headers-result" style="display: none; margin-top: 12px;"></div>
	<?php
}

/**
 * 設定ページ全体の描画。
 */
function render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$all_overridden = is_overridden( 'CONTENT_SIGNAL' ) && is_overridden( 'POST_TYPES' ) && is_overridden( 'CACHE_CONTROL' );
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

		<?php render_status_panel(); ?>

		<hr />

		<?php if ( $all_overridden ) : ?>
			<?php do_settings_sections( 'wp-agent-feed' ); ?>
		<?php else : ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'wp_agent_feed_settings' );
				do_settings_sections( 'wp-agent-feed' );
				submit_button();
				?>
			</form>
		<?php endif; ?>

		<hr />

		<h2><?php esc_html_e( 'Cache Management', 'wp-agent-feed' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %s is the cache directory path */
				esc_html__( 'Cache directory: %s', 'wp-agent-feed' ),
				'<code>' . esc_html( CACHE_DIR ) . '</code>'
			);
			?>
		</p>
		<p>
			<button type="button" class="button button-primary" id="wp-agent-feed-regenerate">
				<?php esc_html_e( 'Regenerate All Cache', 'wp-agent-feed' ); ?>
			</button>
			<button type="button" class="button" id="wp-agent-feed-clear">
				<?php esc_html_e( 'Clear All Cache', 'wp-agent-feed' ); ?>
			</button>
			<span id="wp-agent-feed-status" style="margin-left: 8px;"></span>
		</p>

		<?php render_cache_management_script(); ?>
		<?php render_diagnostics_script(); ?>
	</div>
	<?php
}

/* ========================================
 * Admin: キャッシュ管理 AJAX
 * ======================================== */
add_action( 'wp_ajax_wp_agent_feed_regenerate', __NAMESPACE__ . '\ajax_regenerate' );
add_action( 'wp_ajax_wp_agent_feed_clear', __NAMESPACE__ . '\ajax_clear' );
add_action( 'wp_ajax_wp_agent_feed_live_test', __NAMESPACE__ . '\ajax_live_test' );
add_action( 'wp_ajax_wp_agent_feed_check_headers', __NAMESPACE__ . '\ajax_check_headers' );

/**
 * AJAX: キャッシュ一括再生成（バッチ処理）。
 */
function ajax_regenerate() {
	check_ajax_referer( 'wp_agent_feed_cache' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Permission denied.', 'wp-agent-feed' ), 403 );
	}

	$batch_id = isset( $_POST['batch_id'] ) ? sanitize_text_field( $_POST['batch_id'] ) : '';

	if ( empty( $batch_id ) ) {
		// 初回: ID スナップショットを取得して transient に保存。
		$posts = get_posts(
			array(
				'post_type'      => POST_TYPES,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$batch_id = wp_generate_password( 12, false );
		set_transient( 'wp_agent_feed_batch_' . $batch_id, $posts, HOUR_IN_SECONDS );

		wp_send_json_success(
			array(
				'batch_id' => $batch_id,
				'total'    => count( $posts ),
			)
		);
	}

	// 後続: transient から ID を読み取りバッチ処理。
	$ids = get_transient( 'wp_agent_feed_batch_' . $batch_id );
	if ( false === $ids ) {
		wp_send_json_error( 'batch_expired' );
	}

	$page       = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 0;
	$batch_size = 50;
	$chunk      = array_slice( $ids, $page * $batch_size, $batch_size );

	$processed = 0;
	$failed    = 0;
	foreach ( $chunk as $post_id ) {
		if ( generate_cache( $post_id ) ) {
			++$processed;
		} else {
			++$failed;
		}
	}

	$done = ( ( $page + 1 ) * $batch_size ) >= count( $ids );
	if ( $done ) {
		delete_transient( 'wp_agent_feed_batch_' . $batch_id );
	}

	wp_send_json_success(
		array(
			'processed' => $processed,
			'failed'    => $failed,
			'done'      => $done,
		)
	);
}

/**
 * AJAX: 全キャッシュクリア。
 */
function ajax_clear() {
	check_ajax_referer( 'wp_agent_feed_cache' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Permission denied.', 'wp-agent-feed' ), 403 );
	}

	$count = clear_all_cache();

	wp_send_json_success(
		array(
			/* translators: %d is the number of deleted cache files */
			'message' => sprintf( __( 'Deleted %d cache files.', 'wp-agent-feed' ), $count ),
			'count'   => $count,
		)
	);
}

/**
 * AJAX: 出力検証テスト。
 *
 * 実際の投稿でキャッシュを生成し、ファイルを読み込んで出力を検証する。
 * HTTP ループバック不要のため、Docker 等のコンテナ環境でも動作する。
 */
function ajax_live_test() {
	check_ajax_referer( 'wp_agent_feed_cache' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Permission denied.', 'wp-agent-feed' ), 403 );
	}

	$posts = get_posts(
		array(
			'post_type'      => POST_TYPES,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		)
	);

	if ( empty( $posts ) ) {
		wp_send_json_error(
			array(
				'code'    => 'no_posts',
				'message' => __( 'No published posts found. Publish at least one post to run the test.', 'wp-agent-feed' ),
			)
		);
	}

	$post_id = $posts[0];

	// キャッシュを（再）生成。
	$generated = generate_cache( $post_id );
	if ( ! $generated ) {
		wp_send_json_error(
			array(
				'code'    => 'generate_failed',
				'message' => __( 'Cache generation failed. Check that the cache directory is writable.', 'wp-agent-feed' ),
			)
		);
	}

	$path = cache_path( $post_id );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$body = file_get_contents( $path );
	if ( false === $body ) {
		wp_send_json_error(
			array(
				'code'    => 'read_failed',
				'message' => __( 'Failed to read the generated cache file.', 'wp-agent-feed' ),
			)
		);
	}

	$result = validate_markdown_output( $body, CONTENT_SIGNAL );

	$preview = $body;

	$post_title  = get_the_title( $post_id );
	$token_count = estimate_tokens( $body );

	// serve_markdown() が送信するヘッダーを再現。
	$headers = array(
		'Content-Type'      => 'text/markdown; charset=utf-8',
		'Vary'              => 'Accept',
		'X-Markdown-Tokens' => (string) $token_count,
		'Content-Signal'    => CONTENT_SIGNAL,
		'Content-Length'    => (string) strlen( $body ),
	);
	if ( CACHE_CONTROL !== '' ) {
		$headers['Cache-Control'] = CACHE_CONTROL;
	}

	wp_send_json_success(
		array(
			'pass'    => $result['pass'],
			'checks'  => $result['checks'],
			'post_id' => $post_id,
			'title'   => $post_title,
			'headers' => $headers,
			'preview' => $preview,
		)
	);
}

/**
 * AJAX: 実際の HTTP レスポンスヘッダーを取得。
 */
function ajax_check_headers() {
	check_ajax_referer( 'wp_agent_feed_cache' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Permission denied.', 'wp-agent-feed' ), 403 );
	}

	$posts = get_posts(
		array(
			'post_type'      => POST_TYPES,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		)
	);

	if ( empty( $posts ) ) {
		wp_send_json_error(
			array(
				'code'    => 'no_posts',
				'message' => __( 'No published posts found. Publish at least one post to run the test.', 'wp-agent-feed' ),
			)
		);
	}

	$url = get_permalink( $posts[0] );

	$response = wp_remote_head(
		$url,
		array(
			'timeout' => 10,
			'headers' => array( 'Accept' => 'text/markdown' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		wp_send_json_error(
			array(
				'code'    => 'connection_failed',
				'message' => __( 'Could not connect to the server. This feature may not work in local development environments.', 'wp-agent-feed' ),
			)
		);
	}

	$status      = wp_remote_retrieve_response_code( $response );
	$headers_obj = wp_remote_retrieve_headers( $response );
	$raw_headers = array();
	$fallback    = false;

	if ( is_object( $headers_obj ) && method_exists( $headers_obj, 'getAll' ) ) {
		// WP 6.2+ (Requests v2): getAll() returns all headers including duplicates.
		foreach ( $headers_obj->getAll() as $key => $values ) {
			foreach ( (array) $values as $value ) {
				$raw_headers[] = $key . ': ' . $value;
			}
		}
	} else {
		// Fallback: iterate as array (duplicates may be merged).
		$fallback = true;
		foreach ( $headers_obj as $key => $value ) {
			$raw_headers[] = $key . ': ' . $value;
		}
	}

	$head_warning = ( 405 === $status || 501 === $status || empty( $raw_headers ) );

	wp_send_json_success(
		array(
			'url'          => $url,
			'status'       => $status,
			'raw_headers'  => $raw_headers,
			'head_warning' => $head_warning,
			'fallback'     => $fallback,
		)
	);
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

/**
 * キャッシュ管理ボタンの JavaScript を出力。
 */
function render_cache_management_script() {
	$nonce = wp_create_nonce( 'wp_agent_feed_cache' );
	?>
	<script>
	/* <![CDATA[ */
	(function() {
		var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
		var nonce   = <?php echo wp_json_encode( $nonce ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
		var status  = document.getElementById('wp-agent-feed-status');
		var btnRegen = document.getElementById('wp-agent-feed-regenerate');
		var btnClear = document.getElementById('wp-agent-feed-clear');

		function setButtons(disabled) {
			btnRegen.disabled = disabled;
			btnClear.disabled = disabled;
		}

		function doPost(params) {
			var data = new FormData();
			data.append('_ajax_nonce', nonce);
			for (var k in params) {
				if (params.hasOwnProperty(k)) { data.append(k, params[k]); }
			}
			return fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function(r) { return r.json(); });
		}

		function regenerate(retries) {
			if (typeof retries === 'undefined') { retries = 0; }
			setButtons(true);
			status.textContent = '<?php echo esc_js( __( 'Starting...', 'wp-agent-feed' ) ); ?>';

			doPost({ action: 'wp_agent_feed_regenerate' }).then(function(json) {
				if (!json.success) {
					status.textContent = json.data || 'Error';
					setButtons(false);
					return;
				}
				var batchId = json.data.batch_id;
				var total   = json.data.total;
				if (total === 0) {
					status.textContent = '<?php echo esc_js( __( 'No posts to process.', 'wp-agent-feed' ) ); ?>';
					setButtons(false);
					return;
				}
				processBatch(batchId, total, 0, 0, retries, 0);
			}).catch(function() {
				status.textContent = '<?php echo esc_js( __( 'Request failed.', 'wp-agent-feed' ) ); ?>';
				setButtons(false);
			});
		}

		function processBatch(batchId, total, page, done, retries, totalFailed) {
			var processed = page * 50;
			status.textContent = processed + ' / ' + total + ' <?php echo esc_js( __( 'processed...', 'wp-agent-feed' ) ); ?>';

			doPost({ action: 'wp_agent_feed_regenerate', batch_id: batchId, page: page }).then(function(json) {
				if (!json.success) {
					if (json.data === 'batch_expired' && retries < 3) {
						regenerate(retries + 1);
						return;
					}
					status.textContent = json.data || 'Error';
					setButtons(false);
					return;
				}
				var batchFailed = json.data.failed || 0;
				var newTotalFailed = totalFailed + batchFailed;
				if (json.data.done) {
					if (newTotalFailed > 0) {
						var succeeded = total - newTotalFailed;
						status.textContent = succeeded + ' <?php echo esc_js( __( 'succeeded,', 'wp-agent-feed' ) ); ?> ' + newTotalFailed + ' <?php echo esc_js( __( 'failed.', 'wp-agent-feed' ) ); ?>';
					} else {
						status.textContent = total + ' <?php echo esc_js( __( 'cache files regenerated.', 'wp-agent-feed' ) ); ?>';
					}
					setButtons(false);
				} else {
					processBatch(batchId, total, page + 1, 0, retries, newTotalFailed);
				}
			}).catch(function() {
				status.textContent = '<?php echo esc_js( __( 'Request failed.', 'wp-agent-feed' ) ); ?>';
				setButtons(false);
			});
		}

		btnRegen.addEventListener('click', function() { regenerate(0); });

		btnClear.addEventListener('click', function() {
			if (!confirm('<?php echo esc_js( __( 'Are you sure you want to clear all cache files?', 'wp-agent-feed' ) ); ?>')) {
				return;
			}
			setButtons(true);
			status.textContent = '<?php echo esc_js( __( 'Clearing...', 'wp-agent-feed' ) ); ?>';

			doPost({ action: 'wp_agent_feed_clear' }).then(function(json) {
				status.textContent = json.success ? json.data.message : (json.data || 'Error');
				setButtons(false);
			}).catch(function() {
				status.textContent = '<?php echo esc_js( __( 'Request failed.', 'wp-agent-feed' ) ); ?>';
				setButtons(false);
			});
		});
	})();
	/* ]]> */
	</script>
	<?php
}

/**
 * ライブテストボタンの JavaScript を出力。
 */
function render_diagnostics_script() {
	$nonce = wp_create_nonce( 'wp_agent_feed_cache' );
	?>
	<script>
	/* <![CDATA[ */
	(function() {
		var ajaxUrl  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
		var nonce    = <?php echo wp_json_encode( $nonce ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
		var btn      = document.getElementById('wp-agent-feed-live-test');
		var statusEl = document.getElementById('wp-agent-feed-live-test-status');
		var resultEl = document.getElementById('wp-agent-feed-live-test-result');

		if (!btn) { return; }

		function escHtml(str) {
			var div = document.createElement('div');
			div.appendChild(document.createTextNode(str));
			return div.innerHTML;
		}

		btn.addEventListener('click', function() {
			btn.disabled = true;
			statusEl.textContent = '<?php echo esc_js( __( 'Verifying...', 'wp-agent-feed' ) ); ?>';
			resultEl.style.display = 'none';
			resultEl.innerHTML = '';

			var data = new FormData();
			data.append('action', 'wp_agent_feed_live_test');
			data.append('_ajax_nonce', nonce);

			fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function(r) { return r.json(); })
				.then(function(json) {
					btn.disabled = false;
					statusEl.textContent = '';

					if (!json.success) {
						renderError(json.data);
						return;
					}
					renderResult(json.data);
				})
				.catch(function() {
					btn.disabled = false;
					statusEl.textContent = '';
					renderError({ message: '<?php echo esc_js( __( 'Request failed.', 'wp-agent-feed' ) ); ?>' });
				});
		});

		function renderError(data) {
			var msg = (data && data.message) ? data.message : (data || 'Unknown error');
			var html = '<div class="notice notice-error inline"><p>' + escHtml(msg) + '</p></div>';
			resultEl.innerHTML = html;
			resultEl.style.display = 'block';
		}

		function renderResult(data) {
			var noticeClass = data.pass ? 'notice-success' : 'notice-error';
			var summaryText = data.pass
				? '<?php echo esc_js( __( 'All checks passed. Markdown output is correct.', 'wp-agent-feed' ) ); ?>'
				: '<?php echo esc_js( __( 'Some checks failed.', 'wp-agent-feed' ) ); ?>';

			var html = '<div class="notice ' + noticeClass + ' inline"><p><strong>' +
				escHtml(summaryText) + '</strong></p></div>';

			html += '<p>' + escHtml('<?php echo esc_js( __( 'Tested post:', 'wp-agent-feed' ) ); ?>') +
				' ' + escHtml(data.title) + ' <code>#' + data.post_id + '</code></p>';

			html += '<table class="widefat striped" style="max-width:700px">';
			html += '<thead><tr><th style="width:24px"></th>';
			html += '<th>' + escHtml('<?php echo esc_js( __( 'Check', 'wp-agent-feed' ) ); ?>') + '</th>';
			html += '<th>' + escHtml('<?php echo esc_js( __( 'Expected', 'wp-agent-feed' ) ); ?>') + '</th>';
			html += '<th>' + escHtml('<?php echo esc_js( __( 'Actual', 'wp-agent-feed' ) ); ?>') + '</th>';
			html += '</tr></thead><tbody>';

			for (var i = 0; i < data.checks.length; i++) {
				var c = data.checks[i];
				var icon = c.pass
					? '<span class="dashicons dashicons-yes-alt" style="color:#00a32a"></span>'
					: '<span class="dashicons dashicons-dismiss" style="color:#d63638"></span>';
				html += '<tr><td>' + icon + '</td>';
				html += '<td>' + escHtml(c.name) + '</td>';
				html += '<td>' + escHtml(c.expect) + '</td>';
				html += '<td><code>' + escHtml(c.actual) + '</code></td></tr>';
			}
			html += '</tbody></table>';

			if (data.headers || data.preview) {
				html += '<details style="margin-top:12px">';
				html += '<summary style="cursor:pointer">' + escHtml('<?php echo esc_js( __( 'Response Preview', 'wp-agent-feed' ) ); ?>') + '</summary>';
				var pre = '';
				if (data.headers) {
					for (var h in data.headers) {
						if (data.headers.hasOwnProperty(h)) {
							pre += h + ': ' + data.headers[h] + '\n';
						}
					}
					pre += '\n';
				}
				if (data.preview) {
					pre += data.preview;
				}
				html += '<pre style="background:#f0f0f1;padding:12px;overflow:auto;max-height:300px;margin-top:8px"><code>' +
					escHtml(pre) + '</code></pre></details>';
			}

			resultEl.innerHTML = html;
			resultEl.style.display = 'block';
		}

		/* --- Check HTTP Headers --- */
		var hdrBtn      = document.getElementById('wp-agent-feed-check-headers');
		var hdrStatusEl = document.getElementById('wp-agent-feed-check-headers-status');
		var hdrResultEl = document.getElementById('wp-agent-feed-check-headers-result');

		if (hdrBtn) {
			hdrBtn.addEventListener('click', function() {
				hdrBtn.disabled = true;
				hdrStatusEl.textContent = '<?php echo esc_js( __( 'Checking...', 'wp-agent-feed' ) ); ?>';
				hdrResultEl.style.display = 'none';
				hdrResultEl.innerHTML = '';

				var hdrData = new FormData();
				hdrData.append('action', 'wp_agent_feed_check_headers');
				hdrData.append('_ajax_nonce', nonce);

				fetch(ajaxUrl, { method: 'POST', body: hdrData, credentials: 'same-origin' })
					.then(function(r) { return r.json(); })
					.then(function(json) {
						hdrBtn.disabled = false;
						hdrStatusEl.textContent = '';

						if (!json.success) {
							var errMsg = (json.data && json.data.message) ? json.data.message : (json.data || 'Unknown error');
							hdrResultEl.innerHTML = '<div class="notice notice-error inline"><p>' + escHtml(errMsg) + '</p></div>';
							hdrResultEl.style.display = 'block';
							return;
						}

						var d = json.data;
						var statusCode = d.status || '?';
						var statusClass = (statusCode >= 200 && statusCode < 300) ? 'notice-info' : 'notice-warning';
						var html = '<div class="notice ' + statusClass + ' inline"><p><strong>HTTP ' + escHtml(String(statusCode)) + '</strong> &mdash; ' + escHtml(d.url) + '</p></div>';

						if (d.head_warning) {
							html += '<div class="notice notice-warning inline"><p>' +
								escHtml('<?php echo esc_js( __( 'The server may restrict HEAD requests. If headers appear incorrect, try a GET request with cURL.', 'wp-agent-feed' ) ); ?>') +
								'</p></div>';
						}
						if (d.fallback) {
							html += '<div class="notice notice-info inline"><p>' +
								escHtml('<?php echo esc_js( __( 'Duplicate headers may not be accurately displayed on your WordPress version.', 'wp-agent-feed' ) ); ?>') +
								'</p></div>';
						}

						if (d.raw_headers && d.raw_headers.length) {
							html += '<pre style="background:#f0f0f1;padding:12px;overflow:auto;max-height:300px;margin-top:8px"><code>';
							for (var i = 0; i < d.raw_headers.length; i++) {
								html += escHtml(d.raw_headers[i]) + '\n';
							}
							html += '</code></pre>';
						}

						hdrResultEl.innerHTML = html;
						hdrResultEl.style.display = 'block';
					})
					.catch(function() {
						hdrBtn.disabled = false;
						hdrStatusEl.textContent = '';
						hdrResultEl.innerHTML = '<div class="notice notice-error inline"><p>' +
							escHtml('<?php echo esc_js( __( 'Request failed.', 'wp-agent-feed' ) ); ?>') + '</p></div>';
						hdrResultEl.style.display = 'block';
					});
			});
		}
	})();
	/* ]]> */
	</script>
	<?php
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
