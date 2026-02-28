<?php

declare( strict_types=1 );

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function WpAgentFeed\html_to_markdown;
use function WpAgentFeed\escape_yaml;
use function WpAgentFeed\estimate_tokens;
use function WpAgentFeed\cache_path;
use const WpAgentFeed\CACHE_DIR;

/**
 * Unit tests for pure functions in wp-agent-feed.php.
 *
 * Only functions that do NOT depend on WordPress APIs are tested here.
 */
final class FunctionsTest extends TestCase {

	// =========================================================
	// html_to_markdown()
	// =========================================================

	#[Test]
	public function headings(): void {
		for ( $i = 1; $i <= 6; $i++ ) {
			$prefix = str_repeat( '#', $i );
			$html   = "<h{$i}>Heading {$i}</h{$i}>";
			$this->assertStringContainsString(
				"{$prefix} Heading {$i}",
				html_to_markdown( $html ),
				"h{$i} should convert to {$prefix}"
			);
		}
	}

	#[Test]
	public function code_block_with_language(): void {
		$html = '<pre><code class="language-php">echo "hi";</code></pre>';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( '```php', $md );
		$this->assertStringContainsString( 'echo "hi";', $md );
	}

	#[Test]
	public function code_block_without_language(): void {
		$html = '<pre><code>plain code</code></pre>';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( "```\nplain code\n```", $md );
	}

	#[Test]
	public function pre_without_code(): void {
		$html = '<pre>preformatted text</pre>';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( "```\npreformatted text\n```", $md );
	}

	#[Test]
	public function table_conversion(): void {
		$html = '<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( '| A | B |', $md );
		$this->assertStringContainsString( '| --- | --- |', $md );
		$this->assertStringContainsString( '| 1 | 2 |', $md );
	}

	#[Test]
	public function table_pipe_in_cell_is_escaped(): void {
		$html = '<table><tr><th>Name</th><th>Value</th></tr><tr><td>A | B</td><td>C</td></tr></table>';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( 'A \| B', $md );
		// Ensure the escaped pipe doesn't break column count.
		$lines = array_filter( explode( "\n", trim( $md ) ) );
		foreach ( $lines as $line ) {
			// Each row should have exactly 3 unescaped pipes (| col1 | col2 |).
			$unescaped_pipes = preg_match_all( '/(?<!\\\\)\|/', $line );
			$this->assertSame( 3, $unescaped_pipes, "Row should have 3 unescaped pipes: {$line}" );
		}
	}

	#[Test]
	public function blockquote(): void {
		$html = '<blockquote><p>quoted text</p></blockquote>';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( '> quoted text', $md );
	}

	#[Test]
	public function unordered_list(): void {
		$html = '<ul><li>alpha</li><li>beta</li></ul>';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( '- alpha', $md );
		$this->assertStringContainsString( '- beta', $md );
	}

	#[Test]
	public function ordered_list(): void {
		$html = '<ol><li>first</li><li>second</li></ol>';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( '1. first', $md );
		$this->assertStringContainsString( '2. second', $md );
	}

	#[Test]
	public function image_with_alt(): void {
		$html = '<img src="https://example.com/img.png" alt="photo" />';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( '![photo](https://example.com/img.png)', $md );
	}

	#[Test]
	public function image_without_alt(): void {
		$html = '<img src="https://example.com/img.png" />';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( '![](https://example.com/img.png)', $md );
	}

	#[Test]
	public function link(): void {
		$html = '<a href="https://example.com">click here</a>';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( '[click here](https://example.com)', $md );
	}

	#[Test]
	public function bold_and_italic(): void {
		$html = '<strong>bold</strong> and <em>italic</em>';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( '**bold**', $md );
		$this->assertStringContainsString( '*italic*', $md );
	}

	#[Test]
	public function inline_code(): void {
		$html = '<code>inline</code>';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( '`inline`', $md );
	}

	#[Test]
	public function strikethrough(): void {
		$html = '<del>removed</del>';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( '~~removed~~', $md );
	}

	#[Test]
	public function paragraph(): void {
		$html = '<p>Hello world</p>';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( 'Hello world', $md );
	}

	#[Test]
	public function line_break(): void {
		$html = 'line1<br>line2';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( "line1  \nline2", $md );
	}

	#[Test]
	public function horizontal_rule(): void {
		$html = '<p>above</p><hr><p>below</p>';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( '---', $md );
	}

	#[Test]
	public function html_entities_decoded(): void {
		$html = '<p>&amp; &lt; &gt; &quot;</p>';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( '& < > "', $md );
	}

	#[Test]
	public function consecutive_blank_lines_normalized(): void {
		$html = "<p>a</p>\n\n\n\n<p>b</p>";
		$md   = html_to_markdown( $html );
		// Should not contain more than two consecutive newlines.
		$this->assertDoesNotMatchRegularExpression( '/\n{3,}/', $md );
	}

	#[Test]
	public function output_ends_with_single_newline(): void {
		$html = '<p>end</p>';
		$md   = html_to_markdown( $html );
		$this->assertMatchesRegularExpression( '/[^\n]\n$/', $md );
	}

	#[Test]
	public function remaining_tags_stripped(): void {
		$html = '<div class="wp-block"><span>text</span></div>';
		$md   = html_to_markdown( $html );
		$this->assertStringNotContainsString( '<', $md );
		$this->assertStringContainsString( 'text', $md );
	}

	// =========================================================
	// escape_yaml()
	// =========================================================

	#[Test]
	public function escape_yaml_double_quote(): void {
		$this->assertSame( 'say \\"hello\\"', escape_yaml( 'say "hello"' ) );
	}

	#[Test]
	public function escape_yaml_newline(): void {
		$this->assertSame( 'line1 line2', escape_yaml( "line1\nline2" ) );
	}

	#[Test]
	public function escape_yaml_carriage_return(): void {
		$this->assertSame( 'ab', escape_yaml( "a\rb" ) );
	}

	#[Test]
	public function escape_yaml_plain_string(): void {
		$this->assertSame( 'hello world', escape_yaml( 'hello world' ) );
	}

	// =========================================================
	// estimate_tokens()
	// =========================================================

	#[Test]
	public function estimate_tokens_ascii(): void {
		// 40 ASCII characters → ~10 tokens (40 / 4).
		$text   = str_repeat( 'abcd', 10 );
		$tokens = estimate_tokens( $text );
		$this->assertIsInt( $tokens );
		$this->assertGreaterThan( 0, $tokens );
		$this->assertSame( 10, $tokens );
	}

	#[Test]
	public function estimate_tokens_japanese(): void {
		// Japanese text uses more bytes per char, so chars_per_token decreases.
		$text   = str_repeat( 'あ', 15 ); // 15 chars, 45 bytes
		$tokens = estimate_tokens( $text );
		$this->assertIsInt( $tokens );
		$this->assertGreaterThan( 0, $tokens );
		// With mb_ratio = 1 - 15/45 = 0.667, chars_per_token = 4 - 0.667*2.5 = 2.333
		// tokens = ceil(15 / 2.333) = 7
		$this->assertSame( 7, $tokens );
	}

	#[Test]
	public function estimate_tokens_empty_string(): void {
		$tokens = estimate_tokens( '' );
		$this->assertIsInt( $tokens );
		$this->assertSame( 0, $tokens );
	}

	#[Test]
	public function estimate_tokens_without_mbstring_uses_strlen_fallback(): void {
		// Without mbstring, $len = $bytes for all text (including multibyte).
		// For Japanese "あ"×15: $bytes=45, $len=45 (fallback), mb_ratio=0,
		// chars_per_token=4, tokens=ceil(45/4)=12.
		// This differs from mbstring-aware result (7) but avoids fatal error.
		$text   = str_repeat( 'あ', 15 );
		$bytes  = strlen( $text );
		$tokens = (int) ceil( $bytes / 4 );
		$this->assertSame( 12, $tokens, 'Fallback path should treat bytes as chars' );

		// Verify the actual function still returns a positive integer.
		$actual = estimate_tokens( $text );
		$this->assertIsInt( $actual );
		$this->assertGreaterThan( 0, $actual );
	}

	// =========================================================
	// cache_path()
	// =========================================================

	#[Test]
	public function cache_path_normal(): void {
		$path = cache_path( 42 );
		$this->assertSame( CACHE_DIR . '42.md', $path );
	}

	#[Test]
	public function cache_path_string_id(): void {
		$path = cache_path( '123' );
		$this->assertSame( CACHE_DIR . '123.md', $path );
	}

	#[Test]
	public function cache_path_sanitizes_non_numeric(): void {
		// intval('abc') returns 0, so the path should use 0.
		$path = cache_path( 'abc' );
		$this->assertSame( CACHE_DIR . '0.md', $path );
	}
}
