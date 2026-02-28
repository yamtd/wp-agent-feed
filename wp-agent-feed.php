<?php
/**
 * Plugin Name: WP Agent Feed
 * Plugin URI: https://github.com/your-repo/wp-agent-feed
 * Description: Accept: text/markdown ヘッダー付きリクエストに対して、投稿コンテンツをMarkdownで返す。保存時に静的キャッシュを生成するパフォーマンス重視設計。
 * Version: 1.1.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: wp-agent-feed
 */

defined( 'ABSPATH' ) || exit;

/* ========================================
 * 設定
 * ======================================== */
if ( ! defined( 'WAF_CACHE_DIR' ) ) {
	define( 'WAF_CACHE_DIR', WP_CONTENT_DIR . '/cache/markdown/' );
}
if ( ! defined( 'WAF_POST_TYPES' ) ) {
	define( 'WAF_POST_TYPES', [ 'post', 'page' ] );
}
if ( ! defined( 'WAF_CONTENT_SIGNAL' ) ) {
	define( 'WAF_CONTENT_SIGNAL', 'ai-train=no, search=yes, ai-input=yes' );
}

/* ========================================
 * 1. 早期インターセプト — Accept ヘッダーの確認とキャッシュ配信
 * ======================================== */
add_action( 'wp', 'waf_serve_markdown', 1 );

function waf_serve_markdown() {
	$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
	if ( strpos( $accept, 'text/markdown' ) === false ) {
		return;
	}

	if ( ! is_singular( WAF_POST_TYPES ) ) {
		return;
	}

	$post_id    = get_queried_object_id();
	$cache_path = waf_cache_path( $post_id );

	if ( ! file_exists( $cache_path ) ) {
		waf_generate_cache( $post_id );
	}

	if ( ! file_exists( $cache_path ) ) {
		return;
	}

	$markdown = file_get_contents( $cache_path );
	if ( false === $markdown ) {
		return;
	}
	$token_count = waf_estimate_tokens( $markdown );

	status_header( 200 );
	header( 'Content-Type: text/markdown; charset=utf-8' );
	header( 'Vary: Accept' );
	header( 'X-Markdown-Tokens: ' . $token_count );
	header( 'Content-Signal: ' . WAF_CONTENT_SIGNAL );
	header( 'Content-Length: ' . strlen( $markdown ) );
	header( 'Cache-Control: public, max-age=3600' );

	echo $markdown;
	exit;
}

/* ========================================
 * 2. 保存時キャッシュ生成
 * ======================================== */
add_action( 'save_post', 'waf_on_save_post', 20, 2 );

function waf_on_save_post( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( ! in_array( $post->post_type, WAF_POST_TYPES, true ) ) {
		return;
	}

	if ( $post->post_status === 'publish' ) {
		waf_generate_cache( $post_id );
	} else {
		waf_delete_cache( $post_id );
	}
}

add_action( 'trashed_post', 'waf_delete_cache' );
add_action( 'deleted_post', 'waf_delete_cache' );

/* ========================================
 * 3. Markdown生成（コア処理）
 * ======================================== */
function waf_generate_cache( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || $post->post_status !== 'publish' ) {
		return false;
	}

	$frontmatter = waf_build_frontmatter( $post );
	$html        = waf_get_rendered_content( $post );
	$markdown    = $frontmatter . waf_html_to_markdown( $html );

	if ( ! is_dir( WAF_CACHE_DIR ) ) {
		wp_mkdir_p( WAF_CACHE_DIR );
		file_put_contents( WAF_CACHE_DIR . '.htaccess', "# Apache 2.4+\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n\n# Apache 2.2\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n" );
		file_put_contents( WAF_CACHE_DIR . 'index.html', '' );
	}

	return file_put_contents( waf_cache_path( $post_id ), $markdown ) !== false;
}

/**
 * the_content フィルターを安全に適用。
 */
function waf_get_rendered_content( $post ) {
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
function waf_build_frontmatter( $post ) {
	$title       = $post->post_title;
	$description = get_the_excerpt( $post );
	$url         = get_permalink( $post );
	$date        = get_the_date( 'Y-m-d', $post );
	$modified    = get_the_modified_date( 'Y-m-d', $post );
	$image       = get_the_post_thumbnail_url( $post, 'full' ) ?: '';

	$lines = [
		'---',
		'title: "' . waf_escape_yaml( $title ) . '"',
		'description: "' . waf_escape_yaml( $description ) . '"',
		'url: ' . $url,
		'date: ' . $date,
		'modified: ' . $modified,
	];

	if ( $image ) {
		$lines[] = 'image: ' . $image;
	}

	$lines[] = '---';
	$lines[] = '';

	return implode( "\n", $lines );
}

/* ========================================
 * 5. HTML → Markdown 変換（軽量・依存なし）
 * ======================================== */
function waf_html_to_markdown( $html ) {
	$md = trim( $html );

	// 見出し h1-h6
	for ( $i = 6; $i >= 1; $i-- ) {
		$prefix = str_repeat( '#', $i );
		$md     = preg_replace(
			'/<h' . $i . '[^>]*>(.*?)<\/h' . $i . '>/si',
			"\n" . $prefix . ' $1' . "\n",
			$md
		);
	}

	// pre > code ブロック
	$md = preg_replace_callback(
		'/<pre[^>]*>\s*<code[^>]*?(?:class=["\'](?:language-)?(\w+)["\'][^>]*)?>(.*?)<\/code>\s*<\/pre>/si',
		function ( $m ) {
			$lang = $m[1] ?? '';
			$code = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
			return "\n```" . $lang . "\n" . $code . "\n```\n";
		},
		$md
	);

	// <pre> 単体
	$md = preg_replace_callback(
		'/<pre[^>]*>(.*?)<\/pre>/si',
		function ( $m ) {
			$code = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
			return "\n```\n" . $code . "\n```\n";
		},
		$md
	);

	// テーブル
	$md = preg_replace_callback(
		'/<table[^>]*>(.*?)<\/table>/si',
		'waf_convert_table',
		$md
	);

	// blockquote
	$md = preg_replace_callback(
		'/<blockquote[^>]*>(.*?)<\/blockquote>/si',
		function ( $m ) {
			$inner = waf_html_to_markdown( trim( $m[1] ) );
			$lines = explode( "\n", trim( $inner ) );
			return "\n" . implode( "\n", array_map( fn( $l ) => '> ' . $l, $lines ) ) . "\n";
		},
		$md
	);

	// 順序なしリスト
	$md = preg_replace_callback(
		'/<ul[^>]*>(.*?)<\/ul>/si',
		function ( $m ) {
			return "\n" . preg_replace( '/<li[^>]*>(.*?)<\/li>/si', "- $1\n", $m[1] ) . "\n";
		},
		$md
	);

	// 順序付きリスト
	$md = preg_replace_callback(
		'/<ol[^>]*>(.*?)<\/ol>/si',
		function ( $m ) {
			$i = 0;
			return "\n" . preg_replace_callback(
				'/<li[^>]*>(.*?)<\/li>/si',
				function ( $lm ) use ( &$i ) {
					$i++;
					return $i . '. ' . $lm[1] . "\n";
				},
				$m[1]
			) . "\n";
		},
		$md
	);

	// 画像（3パターン）
	$md = preg_replace(
		'/<img[^>]*src=["\']([^"\']+)["\'][^>]*alt=["\']([^"\']*)["\'][^>]*\/?>/si',
		'![$2]($1)',
		$md
	);
	$md = preg_replace(
		'/<img[^>]*alt=["\']([^"\']*)["\'][^>]*src=["\']([^"\']+)["\'][^>]*\/?>/si',
		'![$1]($2)',
		$md
	);
	$md = preg_replace(
		'/<img[^>]*src=["\']([^"\']+)["\'][^>]*\/?>/si',
		'![]($1)',
		$md
	);

	// リンク
	$md = preg_replace(
		'/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si',
		'[$2]($1)',
		$md
	);

	// インライン要素
	$md = preg_replace( '/<strong[^>]*>(.*?)<\/strong>/si', '**$1**', $md );
	$md = preg_replace( '/<b[^>]*>(.*?)<\/b>/si', '**$1**', $md );
	$md = preg_replace( '/<em[^>]*>(.*?)<\/em>/si', '*$1*', $md );
	$md = preg_replace( '/<i[^>]*>(.*?)<\/i>/si', '*$1*', $md );
	$md = preg_replace( '/<code[^>]*>(.*?)<\/code>/si', '`$1`', $md );
	$md = preg_replace( '/<del[^>]*>(.*?)<\/del>/si', '~~$1~~', $md );

	// 段落
	$md = preg_replace( '/<p[^>]*>/si', "\n", $md );
	$md = str_replace( '</p>', "\n", $md );

	// <br>
	$md = preg_replace( '/<br\s*\/?>/si', "  \n", $md );

	// <hr>
	$md = preg_replace( '/<hr[^>]*\/?>/si', "\n---\n", $md );

	// figure / figcaption
	$md = preg_replace( '/<\/?figure[^>]*>/si', "\n", $md );
	$md = preg_replace( '/<figcaption[^>]*>(.*?)<\/figcaption>/si', '*$1*', $md );

	// 残りのHTMLタグを除去
	$md = strip_tags( $md );

	// HTMLエンティティをデコード
	$md = html_entity_decode( $md, ENT_QUOTES, 'UTF-8' );

	// 連続空行を正規化
	$md = preg_replace( '/\n{3,}/', "\n\n", $md );

	return trim( $md ) . "\n";
}

/**
 * HTML <table> → Markdown テーブル
 */
function waf_convert_table( $matches ) {
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

function waf_cache_path( $post_id ) {
	return WAF_CACHE_DIR . intval( $post_id ) . '.md';
}

function waf_delete_cache( $post_id ) {
	$path = waf_cache_path( $post_id );
	if ( file_exists( $path ) ) {
		unlink( $path );
	}
}

function waf_escape_yaml( $str ) {
	return str_replace( [ '"', "\n", "\r" ], [ '\\"', ' ', '' ], $str );
}

/**
 * トークン数の推定（英語: ~4文字/token、日本語: ~1.5文字/token の加重平均）
 */
function waf_estimate_tokens( $text ) {
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
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command(
		'markdown-cache',
		function ( $args, $assoc_args ) {
			if ( empty( $args[0] ) || $args[0] !== 'regenerate' ) {
				WP_CLI::error( 'Usage: wp markdown-cache regenerate' );
			}

			$posts = get_posts(
				[
					'post_type'      => WAF_POST_TYPES,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				]
			);

			$count = count( $posts );
			WP_CLI::log( "Regenerating markdown cache for {$count} posts..." );

			foreach ( $posts as $i => $post_id ) {
				waf_generate_cache( $post_id );
				WP_CLI::log( sprintf( '  [%d/%d] Post #%d', $i + 1, $count, $post_id ) );
			}

			WP_CLI::success( "Done. {$count} cache files generated in " . WAF_CACHE_DIR );
		}
	);
}
