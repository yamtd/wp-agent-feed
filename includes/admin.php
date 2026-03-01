<?php
/**
 * Admin settings page, AJAX handlers, and diagnostics.
 *
 * Loaded only when is_admin() is true.
 *
 * @package WpAgentFeed
 */

namespace WpAgentFeed;

defined( 'ABSPATH' ) || exit;

/* ========================================
 * Admin: 設定ページ
 * ======================================== */
add_action( 'admin_menu', __NAMESPACE__ . '\add_admin_menu' );
add_action( 'admin_init', __NAMESPACE__ . '\register_settings' );
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_admin_assets' );

/**
 * 設定ページでのみ管理画面用 CSS を読み込み。
 *
 * @param string $hook_suffix 現在の管理ページのフックサフィックス。
 */
function enqueue_admin_assets( $hook_suffix ) {
	if ( 'settings_page_wp-agent-feed' !== $hook_suffix ) {
		return;
	}
	$css_file = dirname( __DIR__ ) . '/assets/admin.css';
	wp_enqueue_style(
		'wp-agent-feed-admin',
		plugin_dir_url( __DIR__ ) . 'assets/admin.css',
		array(),
		file_exists( $css_file ) ? (string) filemtime( $css_file ) : '1'
	);
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
		'Configure how your content is served to AI agents and search tools that request Markdown format.',
		'wp-agent-feed'
	);
	echo '</p>';
	echo '<p class="description">';
	esc_html_e(
		'Advanced: Settings defined as constants in wp-config.php take priority over these values.',
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
	esc_html_e(
		'This HTTP header tells AI agents how they may use your content. The default allows search engines and AI assistants to read your content but disallows AI model training.',
		'wp-agent-feed'
	);
	echo '</p>';
	echo '<p class="description">';
	printf(
		/* translators: 1: ai-train directive, 2: search directive, 3: ai-input directive */
		esc_html__( '%1$s = disallow AI training, %2$s = allow search indexing, %3$s = allow AI assistants to reference your content when answering user questions.', 'wp-agent-feed' ),
		'<code>ai-train=no</code>',
		'<code>search=yes</code>',
		'<code>ai-input=yes</code>'
	);
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
	esc_html_e(
		'Controls how long browsers and CDNs may cache the Markdown response. The default "public, max-age=3600" allows caching for 1 hour. Leave empty to let the server decide.',
		'wp-agent-feed'
	);
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
			'<label class="post-type-label"><input type="checkbox" name="wp_agent_feed_post_types[]" value="%s" %s /> %s (<code>%s</code>)</label>',
			esc_attr( $pt->name ),
			checked( in_array( $pt->name, $current, true ), true, false ),
			esc_html( $pt->labels->name ),
			esc_html( $pt->name )
		);
	}

	echo '<p class="description">';
	esc_html_e(
		'Select which post types are available as Markdown for AI agents and search tools. Only published content of the selected types will be served.',
		'wp-agent-feed'
	);
	echo '</p>';
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
 * 診断データからステータス行の表示状態を導出。
 *
 * JS 側にロジックを複製しないよう、PHP で判定・翻訳まで完了した状態を返す。
 *
 * @param array $data get_diagnostics_data() の返り値。
 * @return array[] 各行の { id, icon, css_class, text }。
 */
function build_status_rows( $data ) {
	$rows = array();

	// Cache directory.
	if ( $data['cache_dir_writable'] ) {
		$rows[] = array(
			'id'        => 'dir',
			'icon'      => 'dashicons-yes-alt',
			'css_class' => 'status-ok',
			'text'      => __( 'Writable', 'wp-agent-feed' ),
		);
	} elseif ( $data['cache_dir_exists'] ) {
		$rows[] = array(
			'id'        => 'dir',
			'icon'      => 'dashicons-dismiss',
			'css_class' => 'status-error',
			'text'      => __( 'Not writable', 'wp-agent-feed' ),
		);
	} else {
		$rows[] = array(
			'id'        => 'dir',
			'icon'      => 'dashicons-dismiss',
			'css_class' => 'status-error',
			'text'      => __( 'Does not exist', 'wp-agent-feed' ),
		);
	}

	// .htaccess.
	if ( $data['htaccess_exists'] ) {
		$rows[] = array(
			'id'        => 'ht',
			'icon'      => 'dashicons-yes-alt',
			'css_class' => 'status-ok',
			'text'      => __( 'Present', 'wp-agent-feed' ),
		);
	} else {
		$rows[] = array(
			'id'        => 'ht',
			'icon'      => 'dashicons-dismiss',
			'css_class' => 'status-error',
			'text'      => __( 'Missing', 'wp-agent-feed' ),
		);
	}

	// Cache coverage.
	$cached = $data['cached_count'];
	$total  = $data['total_count'];

	if ( 0 === $total ) {
		$rows[] = array(
			'id'        => 'cov',
			'icon'      => 'dashicons-minus',
			'css_class' => 'status-neutral',
			'text'      => __( 'No published posts', 'wp-agent-feed' ),
		);
	} elseif ( $cached >= $total ) {
		$rows[] = array(
			'id'        => 'cov',
			'icon'      => 'dashicons-yes-alt',
			'css_class' => 'status-ok',
			/* translators: 1: cached count, 2: total count */
			'text'      => sprintf( __( '%1$d / %2$d posts cached', 'wp-agent-feed' ), min( $cached, $total ), $total ),
		);
	} elseif ( $cached > 0 ) {
		$rows[] = array(
			'id'        => 'cov',
			'icon'      => 'dashicons-warning',
			'css_class' => 'status-warn',
			/* translators: 1: cached count, 2: total count */
			'text'      => sprintf( __( '%1$d / %2$d posts cached', 'wp-agent-feed' ), $cached, $total ),
		);
	} else {
		$rows[] = array(
			'id'        => 'cov',
			'icon'      => 'dashicons-dismiss',
			'css_class' => 'status-error',
			/* translators: 1: cached count, 2: total count */
			'text'      => sprintf( __( '%1$d / %2$d posts cached', 'wp-agent-feed' ), $cached, $total ),
		);
	}

	return $rows;
}

/**
 * ステータス & 診断パネルの HTML を描画。
 */
function render_status_panel() {
	$data = get_diagnostics_data();

	// Cache directory status.
	$rows = build_status_rows( $data );
	$labels = array(
		'dir' => __( 'Cache directory', 'wp-agent-feed' ),
		'ht'  => __( '.htaccess protection', 'wp-agent-feed' ),
		'cov' => __( 'Cache coverage', 'wp-agent-feed' ),
	);
	?>
	<h2><?php esc_html_e( 'Status', 'wp-agent-feed' ); ?></h2>
	<table class="widefat striped status-table">
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
			<tr>
				<td class="status-icon"><span id="agfd-status-<?php echo esc_attr( $row['id'] ); ?>-icon" class="dashicons <?php echo esc_attr( $row['icon'] ); ?> <?php echo esc_attr( $row['css_class'] ); ?>"></span></td>
				<td><?php echo esc_html( $labels[ $row['id'] ] ); ?></td>
				<td id="agfd-status-<?php echo esc_attr( $row['id'] ); ?>-text"><?php echo esc_html( $row['text'] ); ?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

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
		<span id="wp-agent-feed-status" class="inline-status"></span>
	</p>

	<h2><?php esc_html_e( 'Diagnostics', 'wp-agent-feed' ); ?></h2>
	<p class="button-group">
		<button type="button" class="button" id="wp-agent-feed-live-test">
			<?php esc_html_e( 'Verify Output', 'wp-agent-feed' ); ?>
		</button>
		<span id="wp-agent-feed-live-test-status" class="inline-status"></span>
	</p>
	<div id="wp-agent-feed-live-test-result" class="result-panel"></div>
	<p class="button-group">
		<button type="button" class="button" id="wp-agent-feed-check-headers">
			<?php esc_html_e( 'Check HTTP Headers', 'wp-agent-feed' ); ?>
		</button>
		<span id="wp-agent-feed-check-headers-status" class="inline-status"></span>
	</p>
	<div id="wp-agent-feed-check-headers-result" class="result-panel"></div>
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
	<div class="wrap agfd-settings">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

		<div class="tabs">
			<input type="radio" name="agfd-tab" id="agfd-tab-settings" checked class="tab-radio" />
			<label for="agfd-tab-settings" class="tab-label"><?php esc_html_e( 'Settings', 'wp-agent-feed' ); ?></label>

			<input type="radio" name="agfd-tab" id="agfd-tab-status" class="tab-radio" />
			<label for="agfd-tab-status" class="tab-label"><?php esc_html_e( 'Status & Tools', 'wp-agent-feed' ); ?></label>

			<div class="tab-panel panel-settings">
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
			</div>

			<div class="tab-panel panel-status">
				<?php render_status_panel(); ?>
			</div>
		</div>

		<?php render_admin_script(); ?>
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
add_action( 'wp_ajax_wp_agent_feed_diagnostics', __NAMESPACE__ . '\ajax_diagnostics' );

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
 * AJAX: ステータスパネル診断データ取得。
 */
function ajax_diagnostics() {
	check_ajax_referer( 'wp_agent_feed_cache' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Permission denied.', 'wp-agent-feed' ), 403 );
	}

	$data = get_diagnostics_data();
	$rows = build_status_rows( $data );

	wp_send_json_success( array( 'rows' => $rows ) );
}

/**
 * 管理画面の JavaScript を出力（キャッシュ管理 + 診断 + ステータス更新）。
 */
function render_admin_script() {
	$nonce = wp_create_nonce( 'wp_agent_feed_cache' );
	?>
	<script>
	/* <![CDATA[ */
	(function() {
		var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
		var nonce   = <?php echo wp_json_encode( $nonce ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;

		/* --- Shared utilities --- */

		function doPost(params) {
			var data = new FormData();
			data.append('_ajax_nonce', nonce);
			for (var k in params) {
				if (params.hasOwnProperty(k)) { data.append(k, params[k]); }
			}
			return fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function(r) { return r.json(); });
		}

		function escHtml(str) {
			var div = document.createElement('div');
			div.appendChild(document.createTextNode(str));
			return div.innerHTML;
		}

		function refreshStatus() {
			doPost({ action: 'wp_agent_feed_diagnostics' }).then(function(json) {
				if (!json.success) { return; }
				json.data.rows.forEach(function(row) {
					var icon = document.getElementById('agfd-status-' + row.id + '-icon');
					var text = document.getElementById('agfd-status-' + row.id + '-text');
					if (icon) {
						icon.className = 'dashicons ' + row.icon + ' ' + row.css_class;
					}
					if (text) {
						text.textContent = row.text;
					}
				});
			});
		}

		/* --- Cache management --- */

		var status   = document.getElementById('wp-agent-feed-status');
		var btnRegen = document.getElementById('wp-agent-feed-regenerate');
		var btnClear = document.getElementById('wp-agent-feed-clear');

		function setButtons(disabled) {
			btnRegen.disabled = disabled;
			btnClear.disabled = disabled;
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
					refreshStatus();
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
				refreshStatus();
			}).catch(function() {
				status.textContent = '<?php echo esc_js( __( 'Request failed.', 'wp-agent-feed' ) ); ?>';
				setButtons(false);
			});
		});

		/* --- Verify Output --- */

		var testBtn      = document.getElementById('wp-agent-feed-live-test');
		var testStatusEl = document.getElementById('wp-agent-feed-live-test-status');
		var testResultEl = document.getElementById('wp-agent-feed-live-test-result');

		if (testBtn) {
			testBtn.addEventListener('click', function() {
				testBtn.disabled = true;
				testStatusEl.textContent = '<?php echo esc_js( __( 'Verifying...', 'wp-agent-feed' ) ); ?>';
				testResultEl.style.display = 'none';
				testResultEl.innerHTML = '';

				doPost({ action: 'wp_agent_feed_live_test' })
					.then(function(json) {
						testBtn.disabled = false;
						testStatusEl.textContent = '';

						if (!json.success) {
							renderTestError(json.data);
							return;
						}
						renderTestResult(json.data);
						refreshStatus();
					})
					.catch(function() {
						testBtn.disabled = false;
						testStatusEl.textContent = '';
						renderTestError({ message: '<?php echo esc_js( __( 'Request failed.', 'wp-agent-feed' ) ); ?>' });
					});
			});
		}

		function renderTestError(data) {
			var msg = (data && data.message) ? data.message : (data || 'Unknown error');
			testResultEl.innerHTML = '<div class="notice notice-error inline"><p>' + escHtml(msg) + '</p></div>';
			testResultEl.style.display = 'block';
		}

		function renderTestResult(data) {
			var noticeClass = data.pass ? 'notice-success' : 'notice-error';
			var summaryText = data.pass
				? '<?php echo esc_js( __( 'All checks passed. Markdown output is correct.', 'wp-agent-feed' ) ); ?>'
				: '<?php echo esc_js( __( 'Some checks failed.', 'wp-agent-feed' ) ); ?>';

			var html = '<div class="notice ' + noticeClass + ' inline"><p><strong>' +
				escHtml(summaryText) + '</strong></p></div>';

			html += '<p>' + escHtml('<?php echo esc_js( __( 'Tested post:', 'wp-agent-feed' ) ); ?>') +
				' ' + escHtml(data.title) + ' <code>#' + data.post_id + '</code></p>';

			html += '<table class="widefat striped check-table">';
			html += '<thead><tr><th class="status-icon"></th>';
			html += '<th>' + escHtml('<?php echo esc_js( __( 'Check', 'wp-agent-feed' ) ); ?>') + '</th>';
			html += '<th>' + escHtml('<?php echo esc_js( __( 'Expected', 'wp-agent-feed' ) ); ?>') + '</th>';
			html += '<th>' + escHtml('<?php echo esc_js( __( 'Actual', 'wp-agent-feed' ) ); ?>') + '</th>';
			html += '</tr></thead><tbody>';

			for (var i = 0; i < data.checks.length; i++) {
				var c = data.checks[i];
				var icon = c.pass
					? '<span class="dashicons dashicons-yes-alt status-ok"></span>'
					: '<span class="dashicons dashicons-dismiss status-error"></span>';
				html += '<tr><td class="status-icon">' + icon + '</td>';
				html += '<td>' + escHtml(c.name) + '</td>';
				html += '<td>' + escHtml(c.expect) + '</td>';
				html += '<td><code>' + escHtml(c.actual) + '</code></td></tr>';
			}
			html += '</tbody></table>';

			if (data.headers || data.preview) {
				html += '<details class="response-details">';
				html += '<summary>' + escHtml('<?php echo esc_js( __( 'Response Preview', 'wp-agent-feed' ) ); ?>') + '</summary>';
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
				html += '<pre class="code-preview"><code>' + escHtml(pre) + '</code></pre></details>';
			}

			testResultEl.innerHTML = html;
			testResultEl.style.display = 'block';
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

				doPost({ action: 'wp_agent_feed_check_headers' })
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
							html += '<pre class="code-preview"><code>';
							for (var j = 0; j < d.raw_headers.length; j++) {
								html += escHtml(d.raw_headers[j]) + '\n';
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
