<?php
/**
 * HTML → Markdown conversion and pure utility functions.
 *
 * All functions in this file are pure (no WordPress dependency).
 *
 * @package WpAgentFeed
 */

namespace WpAgentFeed;

defined( 'ABSPATH' ) || exit;

/**
 * HTML → Markdown 変換
 *
 * @param string $html HTML string.
 * @return string Markdown string.
 */
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
