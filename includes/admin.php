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
