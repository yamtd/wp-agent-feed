<?php
/**
 * Plugin Name: WP Agent Feed
 * Plugin URI: https://github.com/yamtd/wp-agent-feed
 * Description: Accept: text/markdown ヘッダー付きリクエストに対して、投稿コンテンツをMarkdownで返す。保存時に静的キャッシュを生成するパフォーマンス重視設計。
 * Version: 0.1.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: wp-agent-feed
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
	header( 'Cache-Control: public, max-age=3600' );

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
 * 設定ページ全体の描画。
 */
function render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$all_overridden = is_overridden( 'CONTENT_SIGNAL' ) && is_overridden( 'POST_TYPES' );
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

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
	</div>
	<?php
}

/* ========================================
 * Admin: キャッシュ管理 AJAX
 * ======================================== */
add_action( 'wp_ajax_wp_agent_feed_regenerate', __NAMESPACE__ . '\ajax_regenerate' );
add_action( 'wp_ajax_wp_agent_feed_clear', __NAMESPACE__ . '\ajax_clear' );

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
	foreach ( $chunk as $post_id ) {
		if ( generate_cache( $post_id ) ) {
			++$processed;
		}
	}

	$done = ( ( $page + 1 ) * $batch_size ) >= count( $ids );
	if ( $done ) {
		delete_transient( 'wp_agent_feed_batch_' . $batch_id );
	}

	wp_send_json_success(
		array(
			'processed' => $processed,
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
				processBatch(batchId, total, 0, 0, retries);
			}).catch(function() {
				status.textContent = '<?php echo esc_js( __( 'Request failed.', 'wp-agent-feed' ) ); ?>';
				setButtons(false);
			});
		}

		function processBatch(batchId, total, page, done, retries) {
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
				if (json.data.done) {
					status.textContent = total + ' <?php echo esc_js( __( 'cache files regenerated.', 'wp-agent-feed' ) ); ?>';
					setButtons(false);
				} else {
					processBatch(batchId, total, page + 1, 0, retries);
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
}
