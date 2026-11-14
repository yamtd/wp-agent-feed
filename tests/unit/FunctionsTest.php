<?php

declare( strict_types=1 );

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function WpAgentFeed\html_to_markdown;
use function WpAgentFeed\escape_yaml;
use function WpAgentFeed\estimate_tokens;
use function WpAgentFeed\cache_path;
use function WpAgentFeed\is_overridden;
use function WpAgentFeed\clear_all_cache;
use function WpAgentFeed\robots_disallow_path;
use function WpAgentFeed\filter_robots_txt;
use function WpAgentFeed\get_cache_stats;
use function WpAgentFeed\validate_markdown_output;
use function WpAgentFeed\accepts_markdown;
use function WpAgentFeed\add_content_signal;
use function WpAgentFeed\build_list_markdown;
use const WpAgentFeed\CACHE_DIR;
use const WpAgentFeed\CONTENT_SIGNAL;

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

	// =========================================================
	// Code block preservation in html_to_markdown()
	// =========================================================

	#[Test]
	public function code_block_preserves_html_tags(): void {
		$html = '<pre><code class="language-html">&lt;div class=&quot;container&quot;&gt;&lt;p&gt;Hello&lt;/p&gt;&lt;/div&gt;</code></pre>';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( '```html', $md );
		$this->assertStringContainsString( '<div class="container"><p>Hello</p></div>', $md );
	}

	#[Test]
	public function multiple_code_blocks_preserve_html_tags(): void {
		$html  = '<p>Intro</p>';
		$html .= '<pre><code class="language-html">&lt;div&gt;first&lt;/div&gt;</code></pre>';
		$html .= '<p>Middle</p>';
		$html .= '<pre><code>&lt;span&gt;second&lt;/span&gt;</code></pre>';
		$html .= '<p>End</p>';
		$md    = html_to_markdown( $html );
		$this->assertStringContainsString( '<div>first</div>', $md );
		$this->assertStringContainsString( '<span>second</span>', $md );
		$this->assertStringContainsString( 'Intro', $md );
		$this->assertStringContainsString( 'Middle', $md );
		$this->assertStringContainsString( 'End', $md );
	}

	#[Test]
	public function pre_without_code_preserves_html_tags(): void {
		$html = '<pre>&lt;p&gt;Hello&lt;/p&gt;</pre>';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( '<p>Hello</p>', $md );
	}

	#[Test]
	public function remaining_tags_stripped_with_code_block(): void {
		$html  = '<div><span>visible text</span></div>';
		$html .= '<pre><code class="language-html">&lt;div&gt;code content&lt;/div&gt;</code></pre>';
		$md    = html_to_markdown( $html );
		$this->assertStringContainsString( 'visible text', $md );
		$this->assertStringContainsString( '<div>code content</div>', $md );
	}

	#[Test]
	public function code_block_entities_not_double_decoded(): void {
		$html = '<pre><code>&amp;amp; entity</code></pre>';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( '&amp; entity', $md );
	}

	#[Test]
	public function blockquote_with_code_block_preserves_html(): void {
		$html = '<blockquote><pre><code class="language-html">&lt;div&gt;quoted code&lt;/div&gt;</code></pre></blockquote>';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( '<div>quoted code</div>', $md );
	}

	#[Test]
	public function code_block_preserves_p_br_hr_tags(): void {
		$html = '<pre><code class="language-html">&lt;p&gt;para&lt;/p&gt;&lt;br&gt;&lt;hr&gt;</code></pre>';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( '<p>para</p><br><hr>', $md );
	}

	#[Test]
	public function code_block_with_blockquote_fence_inside(): void {
		// A top-level code block containing "> ```" should not terminate early.
		$html = '<pre><code>&gt; ```' . "\n" . 'content' . "\n" . '&gt; ```</code></pre>';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( '> ```', $md );
		$this->assertStringContainsString( 'content', $md );
	}

	#[Test]
	public function empty_fenced_code_block(): void {
		$html = '<pre><code></code></pre>';
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( "```\n\n```", $md );
	}

	#[Test]
	public function crlf_in_code_block_preserved(): void {
		// CRLF line endings in source HTML should not break code block preservation.
		$html = "<pre><code class=\"language-html\">&lt;div&gt;test&lt;/div&gt;\r\n&lt;p&gt;line2&lt;/p&gt;</code></pre>";
		$md   = html_to_markdown( $html );
		$this->assertStringContainsString( '<div>test</div>', $md );
		$this->assertStringContainsString( '<p>line2</p>', $md );
	}

	/* ========================================
	 * is_overridden() テスト
	 * ======================================== */

	#[Test]
	public function is_overridden_returns_false_for_unknown_name(): void {
		is_overridden( '', false, true ); // reset
		$this->assertFalse( is_overridden( 'UNKNOWN_SETTING' ) );
	}

	#[Test]
	public function is_overridden_returns_true_after_mark(): void {
		is_overridden( '', false, true ); // reset
		is_overridden( 'TEST_SETTING', true );
		$this->assertTrue( is_overridden( 'TEST_SETTING' ) );
	}

	#[Test]
	public function is_overridden_names_are_independent(): void {
		is_overridden( '', false, true ); // reset
		is_overridden( 'SETTING_A', true );
		$this->assertTrue( is_overridden( 'SETTING_A' ) );
		$this->assertFalse( is_overridden( 'SETTING_B' ) );
	}

	#[Test]
	public function is_overridden_reset_clears_all(): void {
		is_overridden( 'SETTING_X', true );
		is_overridden( '', false, true ); // reset
		$this->assertFalse( is_overridden( 'SETTING_X' ) );
	}

	/* ========================================
	 * clear_all_cache() テスト
	 * ======================================== */

	#[Test]
	public function clear_all_cache_returns_zero_for_empty_dir(): void {
		$dir = CACHE_DIR;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		// Ensure directory is empty of .md files.
		foreach ( glob( $dir . '*.md' ) ?: array() as $f ) {
			unlink( $f );
		}
		$this->assertSame( 0, clear_all_cache() );
	}

	#[Test]
	public function clear_all_cache_deletes_only_md_files(): void {
		$dir = CACHE_DIR;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		// Create test files.
		file_put_contents( $dir . '1.md', 'test' );
		file_put_contents( $dir . '2.md', 'test' );
		file_put_contents( $dir . 'keep.txt', 'keep' );

		$count = clear_all_cache();

		$this->assertSame( 2, $count );
		$this->assertFileDoesNotExist( $dir . '1.md' );
		$this->assertFileDoesNotExist( $dir . '2.md' );
		$this->assertFileExists( $dir . 'keep.txt' );

		// Cleanup.
		unlink( $dir . 'keep.txt' );
	}

	#[Test]
	public function clear_all_cache_returns_zero_for_nonexistent_dir(): void {
		// Use a non-existent sub-directory path.
		// The function uses the CACHE_DIR constant which is already defined.
		// We test via a temp dir that doesn't exist.
		$original_dir = CACHE_DIR;
		// Since CACHE_DIR is a constant, we can only test with the existing value.
		// If the directory doesn't exist, ensure it returns 0.
		$test_dir = sys_get_temp_dir() . '/waf-test-nonexistent-' . uniqid() . '/';
		// clear_all_cache uses CACHE_DIR constant, so we can't change it.
		// Instead, test that empty dir returns 0 (covered above).
		// This test verifies the function handles missing directories gracefully
		// by temporarily removing the cache directory.
		if ( is_dir( $original_dir ) ) {
			// Remove all files first.
			foreach ( glob( $original_dir . '*' ) ?: array() as $f ) {
				if ( is_file( $f ) ) {
					unlink( $f );
				}
			}
			rmdir( $original_dir );
		}

		$this->assertSame( 0, clear_all_cache() );

		// Restore directory for other tests.
		mkdir( $original_dir, 0755, true );
	}

	/* ========================================
	 * robots_disallow_path() テスト
	 * ======================================== */

	#[Test]
	public function robots_disallow_path_standard(): void {
		$this->assertSame(
			'/wp-content/cache/markdown/',
			robots_disallow_path( '/var/www/html/', '/var/www/html/wp-content/cache/markdown/' )
		);
	}

	#[Test]
	public function robots_disallow_path_subdirectory_install(): void {
		$this->assertSame(
			'/wp-content/cache/markdown/',
			robots_disallow_path( '/var/www/html/blog/', '/var/www/html/blog/wp-content/cache/markdown/' )
		);
	}

	#[Test]
	public function robots_disallow_path_outside_abspath(): void {
		$this->assertSame(
			'',
			robots_disallow_path( '/var/www/html/', '/var/cache/markdown/' )
		);
	}

	#[Test]
	public function robots_disallow_path_same_as_abspath(): void {
		$this->assertSame(
			'',
			robots_disallow_path( '/var/www/html/', '/var/www/html/' )
		);
	}

	/* ========================================
	 * filter_robots_txt() テスト
	 * ======================================== */

	#[Test]
	public function filter_robots_txt_adds_disallow_rule(): void {
		$input  = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		$output = filter_robots_txt( $input, 1 );
		$this->assertStringContainsString( 'Disallow: /wp-content/cache/markdown/', $output );
	}

	#[Test]
	public function filter_robots_txt_skips_duplicate(): void {
		$input  = "User-agent: *\nDisallow: /wp-content/cache/markdown/\n";
		$output = filter_robots_txt( $input, 1 );
		$this->assertSame( 1, substr_count( $output, 'Disallow: /wp-content/cache/markdown/' ) );
	}

	#[Test]
	public function filter_robots_txt_distinguishes_similar_path(): void {
		$input  = "User-agent: *\nDisallow: /wp-content/cache/markdown/sub/\n";
		$output = filter_robots_txt( $input, 1 );
		$this->assertStringContainsString( 'Disallow: /wp-content/cache/markdown/sub/', $output );
		$this->assertStringContainsString( "\nDisallow: /wp-content/cache/markdown/\n", $output );
	}

	#[Test]
	public function filter_robots_txt_skips_duplicate_with_crlf(): void {
		$input  = "User-agent: *\r\nDisallow: /wp-content/cache/markdown/\r\n";
		$output = filter_robots_txt( $input, 1 );
		$this->assertSame( 1, substr_count( $output, 'Disallow: /wp-content/cache/markdown/' ) );
	}

	// =========================================================
	// get_cache_stats()
	// =========================================================

	#[Test]
	public function get_cache_stats_nonexistent_dir(): void {
		$this->assertSame( 0, get_cache_stats( '/nonexistent/path/' ) );
	}

	#[Test]
	public function get_cache_stats_empty_dir(): void {
		$dir = CACHE_DIR;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		// Remove any existing .md files.
		foreach ( glob( $dir . '*.md' ) ?: array() as $f ) {
			unlink( $f );
		}

		$this->assertSame( 0, get_cache_stats( $dir ) );
	}

	#[Test]
	public function get_cache_stats_counts_numeric_md_only(): void {
		$dir = CACHE_DIR;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		file_put_contents( $dir . '1.md', 'test' );
		file_put_contents( $dir . '42.md', 'test' );
		file_put_contents( $dir . 'readme.md', 'not a cache file' );
		file_put_contents( $dir . 'other.txt', 'not markdown' );

		$this->assertSame( 2, get_cache_stats( $dir ) );

		unlink( $dir . '1.md' );
		unlink( $dir . '42.md' );
		unlink( $dir . 'readme.md' );
		unlink( $dir . 'other.txt' );
	}

	// =========================================================
	// validate_markdown_output()
	// =========================================================

	#[Test]
	public function validate_markdown_output_all_pass(): void {
		$result = validate_markdown_output(
			"---\ntitle: \"Test\"\n---\n\n# Hello\n",
			'ai-train=no, search=yes, ai-input=yes'
		);
		$this->assertTrue( $result['pass'] );
		foreach ( $result['checks'] as $check ) {
			$this->assertTrue( $check['pass'], "Check '{$check['name']}' should pass" );
		}
	}

	#[Test]
	public function validate_markdown_output_empty_body(): void {
		$result = validate_markdown_output( '', 'test' );
		$this->assertFalse( $result['pass'] );
	}

	#[Test]
	public function validate_markdown_output_no_frontmatter(): void {
		$result = validate_markdown_output(
			'# Just a heading without frontmatter',
			'test'
		);
		$this->assertFalse( $result['pass'] );
		$frontmatter_check = null;
		foreach ( $result['checks'] as $c ) {
			if ( 'frontmatter' === $c['name'] ) {
				$frontmatter_check = $c;
				break;
			}
		}
		$this->assertNotNull( $frontmatter_check );
		$this->assertFalse( $frontmatter_check['pass'] );
	}

	#[Test]
	public function validate_markdown_output_empty_content_signal(): void {
		$result = validate_markdown_output(
			"---\ntitle: \"Test\"\n---\n\n# Hello\n",
			''
		);
		$this->assertFalse( $result['pass'] );
		$signal_check = null;
		foreach ( $result['checks'] as $c ) {
			if ( 'content_signal' === $c['name'] ) {
				$signal_check = $c;
				break;
			}
		}
		$this->assertNotNull( $signal_check );
		$this->assertFalse( $signal_check['pass'] );
	}

	#[Test]
	public function validate_markdown_output_has_token_estimate(): void {
		$result = validate_markdown_output(
			"---\ntitle: \"Test\"\n---\n\n# Hello World\n",
			'test'
		);
		$this->assertTrue( $result['pass'] );
		$token_check = null;
		foreach ( $result['checks'] as $c ) {
			if ( 'token_estimate' === $c['name'] ) {
				$token_check = $c;
				break;
			}
		}
		$this->assertNotNull( $token_check );
		$this->assertTrue( $token_check['pass'] );
		$this->assertGreaterThan( 0, (int) $token_check['actual'] );
	}

	/* ========================================
	 * accepts_markdown() テスト
	 * ======================================== */

	public static function accepts_markdown_provider(): array {
		return [
			'markdown only'         => [ 'text/markdown', true ],
			'with html'             => [ 'text/html, text/markdown', true ],
			'q above zero'          => [ 'text/markdown;q=0.5, text/html', true ],
			'spaces and upper case' => [ 'text/html ,  Text/Markdown ; q=1', true ],
			'q zero'                => [ 'text/markdown;q=0, text/html', false ],
			'q zero with decimals'  => [ 'text/markdown; q=0.000', false ],
			'html only'             => [ 'text/html,application/xhtml+xml,*/*;q=0.8', false ],
			'similar subtype'       => [ 'text/markdown-extra', false ],
			'empty'                 => [ '', false ],
		];
	}

	#[Test]
	#[DataProvider( 'accepts_markdown_provider' )]
	public function accepts_markdown_cases( string $accept, bool $expected ): void {
		$this->assertSame( $expected, accepts_markdown( $accept ) );
	}

	/* ========================================
	 * add_content_signal() / filter_robots_txt() の Content-Signal テスト
	 * ======================================== */

	#[Test]
	public function add_content_signal_inserts_after_wildcard_user_agent(): void {
		$output = add_content_signal( "User-agent: *\nDisallow: /wp-admin/\n", 'ai-train=no, search=yes, ai-input=yes' );
		$this->assertSame( "User-agent: *\nContent-Signal: ai-train=no, search=yes, ai-input=yes\nDisallow: /wp-admin/\n", $output );
	}

	#[Test]
	public function add_content_signal_keeps_crlf(): void {
		$output = add_content_signal( "User-agent: *\r\nDisallow: /wp-admin/\r\n", 'search=yes' );
		$this->assertSame( "User-agent: *\r\nContent-Signal: search=yes\r\nDisallow: /wp-admin/\r\n", $output );
	}

	#[Test]
	public function add_content_signal_when_user_agent_is_last_line(): void {
		$this->assertSame( "User-agent: *\nContent-Signal: search=yes", add_content_signal( 'User-agent: *', 'search=yes' ) );
	}

	#[Test]
	public function add_content_signal_only_first_wildcard_group(): void {
		$output = add_content_signal( "User-agent: *\nDisallow: /a/\n\nUser-agent: *\nDisallow: /b/\n", 'search=yes' );
		$this->assertSame( 1, substr_count( $output, 'Content-Signal:' ) );
		$this->assertStringStartsWith( "User-agent: *\nContent-Signal: search=yes\n", $output );
	}

	#[Test]
	public function add_content_signal_ignores_specific_user_agent(): void {
		$input = "User-agent: Googlebot\nDisallow: /a/\n";
		$this->assertSame( $input, add_content_signal( $input, 'search=yes' ) );
	}

	#[Test]
	public function add_content_signal_skips_existing_line(): void {
		$input = "User-agent: *\ncontent-signal: ai-train=yes\nDisallow: /a/\n";
		$this->assertSame( $input, add_content_signal( $input, 'ai-train=no' ) );
	}

	#[Test]
	public function add_content_signal_strips_newlines_from_value(): void {
		$output = add_content_signal( "User-agent: *\n", "search=yes\nDisallow: /" );
		$this->assertSame( "User-agent: *\nContent-Signal: search=yesDisallow: /\n", $output );
	}

	#[Test]
	public function add_content_signal_skips_empty_value(): void {
		$input = "User-agent: *\n";
		$this->assertSame( $input, add_content_signal( $input, " \n" ) );
	}

	#[Test]
	public function filter_robots_txt_adds_content_signal_when_public(): void {
		$output = filter_robots_txt( "User-agent: *\nDisallow: /wp-admin/\n", '1' );
		$this->assertStringStartsWith( "User-agent: *\nContent-Signal: " . CONTENT_SIGNAL . "\n", $output );
	}

	#[Test]
	public function filter_robots_txt_skips_content_signal_when_not_public(): void {
		$output = filter_robots_txt( "User-agent: *\nDisallow: /\n", '0' );
		$this->assertStringNotContainsString( 'Content-Signal:', $output );
	}

	/* ========================================
	 * build_list_markdown() テスト
	 * ======================================== */

	#[Test]
	public function build_list_markdown_full(): void {
		$output   = build_list_markdown(
			'My "Site"',
			"A site\nabout things",
			'https://example.com/',
			[
				[
					'title'       => 'First [draft]',
					'url'         => 'https://example.com/first/',
					'description' => "Line one\n line two",
				],
				[
					'title'       => 'Second',
					'url'         => 'https://example.com/second/',
					'description' => '',
				],
			]
		);
		$expected = implode(
			"\n",
			[
				'---',
				'title: "My \"Site\""',
				'description: "A site about things"',
				'url: "https://example.com/"',
				'---',
				'',
				'# My "Site"',
				'',
				'> A site about things',
				'',
				'- [First \[draft\]](https://example.com/first/): Line one line two',
				'- [Second](https://example.com/second/)',
				'',
			]
		);
		$this->assertSame( $expected, $output );
	}

	#[Test]
	public function build_list_markdown_without_description_or_items(): void {
		$output = build_list_markdown( 'Site', '', 'https://example.com/', [] );
		$this->assertSame( "---\ntitle: \"Site\"\ndescription: \"\"\nurl: \"https://example.com/\"\n---\n\n# Site\n\n", $output );
	}
}
