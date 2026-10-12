<?php
/** Vietnamese administration with standard WordPress forms and authenticated REST. *
 *
 * @package MultiAISEOContentFactory
 */

namespace MASF;

defined( 'ABSPATH' ) || exit;

/** Admin implementation for the factory workflow. */
final class Admin {
	/**
	 * Administration menu titles.
	 *
	 * @var array
	 */
	private static $pages = array(
		'masf'           => 'Viết bài nhanh',
		'masf-campaigns' => 'Chiến dịch viết bài',
		'masf-matrix'    => 'Danh sách bài',
		'masf-providers' => 'Cài đặt API',
	);
	/**
	 * Boot.
	 *
	 * @return mixed Operation result.
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_masf_action', array( __CLASS__, 'handle' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
	}
	/**
	 * Permission.
	 *
	 * @return mixed Operation result.
	 */
	public static function permission() {
		return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); }
	/**
	 * Menu.
	 *
	 * @return mixed Operation result.
	 */
	public static function menu() {
		add_menu_page( 'AI Content Factory', 'AI Content Factory', 'manage_options', 'masf', array( __CLASS__, 'render' ), 'dashicons-edit-page', 65 );
		foreach ( self::$pages as $slug => $title ) {
			add_submenu_page( 'masf', $title, $title, 'manage_options', $slug, array( __CLASS__, 'render' ) ); }
	}
	/**
	 * Assets.
	 *
	 * @param mixed $hook Hook.
	 *
	 * @return mixed Operation result.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( $hook, 'masf' ) ) {
			return; }
		wp_enqueue_style( 'masf-admin', plugins_url( 'assets/admin.css', MASF_FILE ), array(), MASF_VERSION );
		wp_enqueue_script( 'masf-admin', plugins_url( 'assets/admin.js', MASF_FILE ), array(), MASF_VERSION, true );
		wp_localize_script(
			'masf-admin',
			'MASFAdmin',
			array(
				'url'   => esc_url_raw( rest_url( 'masf/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'providers' => array(
					'claude' => array( 'model' => get_option( 'masf_last_quick_model_claude', Settings::provider( 'claude' )['model'] ), 'tokens' => Settings::provider( 'claude' )['output_tokens'] ),
					'gemini' => array( 'model' => get_option( 'masf_last_quick_model_gemini', Settings::provider( 'gemini' )['model'] ), 'tokens' => Settings::provider( 'gemini' )['output_tokens'] ),
					'openai' => array( 'model' => get_option( 'masf_last_quick_model_openai', Settings::provider( 'openai' )['model'] ), 'tokens' => Settings::provider( 'openai' )['output_tokens'] ),
				),
			)
		);
	}
	/**
	 * Rest.
	 *
	 * @return mixed Operation result.
	 */
	public static function rest() {
		register_rest_route(
			'masf/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'permission' ),
				'callback'            => function () {
					return rest_ensure_response( self::stats() );
				},
			)
		);
		register_rest_route(
			'masf/v1',
			'/provider',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'permission' ),
				'callback'            => function ( $r ) {
					$name = sanitize_key( $r['provider'] );
					if ( ! in_array( $name, array( 'claude', 'gemini', 'openai' ), true ) ) {
						return new \WP_Error( 'invalid', 'Provider không hợp lệ.', array( 'status' => 400 ) ); }
					try {
						if ( 'models' === $r['operation'] ) {
							$models = Providers::models( $name );
							return array(
								'message' => 'Xác thực catalog thành công: ' . count( $models ) . ' model. Chọn model rồi Test Connection để kiểm tra quyền tạo nội dung.',
								'models'  => $models,
							); }
						$config = Settings::provider( $name );
						$result = Providers::call( $name, $config['model'], 'Return only JSON.', 'Reply as JSON {"ok":true}.', 0, 0, 256 );
						Providers::json( $result['text'] );
						// phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Intentional empty-value fallback; plugin requires PHP 8.1+.
						return array( 'message' => 'Kết nối và gọi model thành công. Token input/output: ' . $result['input'] . '/' . $result['output'] . '. Rate remaining (nếu API cung cấp): ' . ( $result['rate_remaining'] ?: 'không cung cấp' ) . '. Không suy ra số dư tài khoản từ kết quả này.' );
					} catch ( API_Error $e ) {
						return new \WP_Error(
							'provider_error',
							$e->getMessage(),
							array(
								'status'      => 400,
								'http'        => $e->http,
								'retry_after' => $e->retry_after,
							)
						); }
				},
			)
		);
	}
	/**
	 * Stats.
	 *
	 * @return mixed Operation result.
	 */
	public static function stats() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Schema/table names are trusted constants from Store::table(); all dynamic values are bound or handled by wpdb.
		$rows   = $wpdb->get_results( 'SELECT status,COUNT(*) n FROM ' . Store::table( 'articles' ) . ' GROUP BY status', ARRAY_A );
		$counts = array_fill_keys( array( 'PLANNED', 'WRITING', 'QA', 'REPAIR', 'READY', 'PUBLISHED', 'FAILED', 'BLOCKED' ), 0 );
		foreach ( $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['n']; }
		$total = array_sum( $counts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Schema/table names are trusted constants from Store::table(); all dynamic values are bound or handled by wpdb.
		$done = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Store::table( 'articles' ) . ' WHERE completed_at IS NOT NULL' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Schema/table names are trusted constants from Store::table(); all dynamic values are bound or handled by wpdb.
		$usage = $wpdb->get_row( 'SELECT COALESCE(SUM(input_tokens),0) input_tokens,COALESCE(SUM(output_tokens),0) output_tokens,COALESCE(SUM(cost),0) cost,COALESCE(SUM(IF(state IN (\'reserved\',\'unknown\'),reserved_cost,0)),0) reserved_cost FROM ' . Store::table( 'requests' ), ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Schema/table names are trusted constants from Store::table(); all dynamic values are bound or handled by wpdb.
		$active = $wpdb->get_results( 'SELECT id,provider,model,phase,lease_until FROM ' . Store::table( 'articles' ) . " WHERE lease_token<>'' ORDER BY updated_at DESC LIMIT 3", ARRAY_A );
		return array(
			'counts'         => $counts,
			'total'          => $total,
			'completed'      => $done,
			'queue'          => $total - $done - $counts['FAILED'] - $counts['BLOCKED'],
			'progress'       => $total ? round( 100 * $done / $total, 1 ) : 0,
			'usage'          => $usage,
			'active'         => $active,
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Schema/table names are trusted constants from Store::table(); all dynamic values are bound or handled by wpdb.
			'last_processed' => $wpdb->get_var( 'SELECT MAX(updated_at) FROM ' . Store::table( 'articles' ) ),
			'errors'         => $counts['FAILED'] + $counts['BLOCKED'],
		);
	}
	/**
	 * Begin.
	 *
	 * @param string $op Op.
	 * @param mixed  $campaign Campaign.
	 * @param bool   $multipart Multipart.
	 *
	 * @return mixed Operation result.
	 */
	private static function begin( $op, $campaign = 0, $multipart = false ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $multipart ? ' enctype="multipart/form-data"' : '' ) . ' class="masf-form">';
		wp_nonce_field( 'masf_action' );
		echo '<input type="hidden" name="action" value="masf_action"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="campaign_id" value="' . absint( $campaign ) . '">';
	}
	/**
	 * Input.
	 *
	 * @param mixed  $label Label.
	 * @param string $name Name.
	 * @param mixed  $value Value.
	 * @param mixed  $type Type.
	 * @param mixed  $extra Extra.
	 *
	 * @return mixed Operation result.
	 */
	private static function input( $label, $name, $value = '', $type = 'text', $extra = '' ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Extra input attributes are developer-only constants, never request data.
		echo '<label>' . esc_html( $label ) . '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" ' . $extra . '></label>'; // $extra is a developer constant, never user input.
	}
	/**
	 * Area.
	 *
	 * @param mixed  $label Label.
	 * @param string $name Name.
	 * @param mixed  $value Value.
	 * @param array  $rows Rows.
	 *
	 * @return mixed Operation result.
	 */
	private static function area( $label, $name, $value = '', $rows = 4 ) {
		echo '<label>' . esc_html( $label ) . '<textarea name="' . esc_attr( $name ) . '" rows="' . absint( $rows ) . '">' . esc_textarea( $value ) . '</textarea></label>';
	}
	/**
	 * Select.
	 *
	 * @param mixed  $label Label.
	 * @param string $name Name.
	 * @param mixed  $options Options.
	 * @param mixed  $value Value.
	 *
	 * @return mixed Operation result.
	 */
	private static function select( $label, $name, $options, $value ) {
		echo '<label>' . esc_html( $label ) . '<select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $key => $text ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $key, $value, false ) . '>' . esc_html( $text ) . '</option>'; }
		echo '</select></label>';
	}
	/**
	 * Check.
	 *
	 * @param mixed  $label Label.
	 * @param string $name Name.
	 * @param mixed  $value Value.
	 *
	 * @return mixed Operation result.
	 */
	private static function check( $label, $name, $value = false ) {
		echo '<label class="masf-check"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( $value, true, false ) . '> ' . esc_html( $label ) . '</label>'; }
	/**
	 * Submit.
	 *
	 * @param mixed $label Label.
	 *
	 * @return mixed Operation result.
	 */
	private static function submit( $label = 'Lưu' ) {
		echo '<button type="submit" class="button button-primary">' . esc_html( $label ) . '</button></form>'; }
	/**
	 * Render.
	 *
	 * @return mixed Operation result.
	 */
	public static function render() {
		if ( ! self::permission() ) {
			wp_die( esc_html( 'Bạn không có quyền quản lý factory.' ), '', array( 'response' => 403 ) ); }
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filters after capability checks; all mutations use verified POST nonces.
		$page = sanitize_key( $_GET['page'] ?? 'masf' );
		echo '<div class="wrap masf"><h1>AI Content Factory — ' . esc_html( self::$pages[ $page ] ?? 'Tổng quan' ) . '</h1>';
		$notice = get_transient( 'masf_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'masf_notice_' . get_current_user_id() );
			echo '<div class="notice notice-info"><p>' . esc_html( $notice ) . '</p></div>'; }
		if ( ! class_exists( '\ZipArchive' ) ) {
			echo '<div class="notice notice-warning"><p>Excel cần PHP Zip; CSV vẫn dùng được. Bật Zip trong cấu hình PHP hosting.</p></div>'; }
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filters after capability checks; all mutations use verified POST nonces.
		if ( ! empty( $_GET['article'] ) && in_array( $page, array( 'masf-matrix', 'masf-qa' ), true ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filters after capability checks; all mutations use verified POST nonces.
			self::detail( absint( $_GET['article'] ) ); } elseif ( 'masf' === $page ) {
			self::quick_form();
			self::dashboard(); } elseif ( 'masf-campaigns' === $page ) {
				self::campaigns(); } elseif ( 'masf-providers' === $page ) {
				self::providers(); } elseif ( 'masf-settings' === $page ) {
					self::settings(); } elseif ( 'masf-logs' === $page ) {
					self::logs(); } else {
						self::matrix( $page ); }
					echo '</div>';
	}
	/**
	 * Dashboard.
	 *
	 * @return mixed Operation result.
	 */
	private static function dashboard() {
		$s = self::stats();
		echo '<div class="masf-panel"><h2>Tiến độ viết bài</h2><p>Đã tạo: <b>' . absint( $s['total'] ) . '</b> · Đã hoàn thành: <b>' . absint( $s['completed'] ) . '</b> · Đang chờ: <b>' . absint( $s['queue'] ) . '</b> · Tiến độ <b id="masf-progress">' . esc_html( $s['progress'] ) . '%</b></p>';
		echo '<progress max="100" value="' . esc_attr( $s['progress'] ) . '"></progress>';
		echo '<p>Token input/output: ' . esc_html( $s['usage']['input_tokens'] . '/' . $s['usage']['output_tokens'] ) . ' · Chi phí ước tính USD: ' . esc_html( $s['usage']['cost'] ) . ' (chỉ có ý nghĩa khi đã cấu hình đơn giá API).</p></div>';
		self::recent_errors();
		self::campaign_table();
	}

	/**
	 * The common path: one form, one campaign, bounded article count.
	 * API keys are write-only; no balance is inferred from a successful test.
	 */
	private static function quick_form() {
		$labels = array( 'claude' => 'Claude', 'gemini' => 'Gemini', 'openai' => 'ChatGPT / OpenAI' );
		$chosen = sanitize_key( (string) get_option( 'masf_last_quick_provider', 'claude' ) );
		if ( ! isset( $labels[ $chosen ] ) ) { $chosen = 'claude'; }
		echo '<div class="masf-panel masf-quick"><h2>✍️ Viết bài Markdown — đơn giản</h2>';
		echo '<p>Nhập API một lần ở <a href="' . esc_url( admin_url( 'admin.php?page=masf-providers' ) ) . '">Cài đặt API</a>. Tại đây chỉ cần chọn AI, tiêu đề, số từ, thẻ heading, danh mục và nhấn Viết.</p>';
		self::begin( 'quick_create', 0, true );
		echo '<div class="masf-grid">';
		self::select( '1. AI viết bài', 'provider', $labels, $chosen );
		echo '<p class="masf-hint masf-key-status">';
		foreach ( $labels as $name => $label ) {
			$d = Settings::provider( $name );
			echo '<span data-key-provider="' . esc_attr( $name ) . '" class="' . ( $name === $chosen ? '' : 'masf-hidden' ) . '">' . esc_html( $label . ': ' . ( Settings::key( $name ) && $d['model'] ? '✅ API và model đã lưu' : '⚠️ Hãy nhập API + Model ID trong Cài đặt API' ) ) . '</span>';
		}
		echo '</p>';
		self::input( '2. Số bài (1–100)', 'count', 1, 'number', 'required min="1" max="100" step="1"' );
		self::input( '3. Số từ mỗi bài (1.500–10.000)', 'words', 1500, 'number', 'required min="1500" max="10000" step="100"' );
		self::select( '4. Ngôn ngữ', 'language', array( 'vi' => 'Tiếng Việt', 'en' => 'English', 'es' => 'Español', 'fr' => 'Français', 'zh' => '中文', 'ko' => '한국어', 'ja' => '日本語' ), 'vi' );
		echo '<label>5. Danh mục cha / con';
		wp_dropdown_categories( array( 'name' => 'category', 'hide_empty' => false, 'hierarchical' => true, 'show_option_none' => false, 'selected' => (int) get_option( 'default_category' ) ) );
		echo '</label>';
		self::select( '6. Lưu bài dưới dạng', 'mode', array( 'draft' => 'Nháp (an toàn)', 'publish' => 'Xuất bản sau khi viết' ), 'draft' );
		echo '</div><h3>Số lượng thẻ tiêu đề trong bài (không tạo H1)</h3><div class="masf-headings">';
		foreach ( array( 'h2' => 5, 'h3' => 6, 'h4' => 0, 'h5' => 0, 'h6' => 0 ) as $tag => $default ) {
			self::input( strtoupper( $tag ), $tag, $default, 'number', 'min="' . ( 'h2' === $tag ? '1' : '0' ) . '" max="20" step="1"' );
		}
		echo '</div>';
		self::area( '7. Nhập tiêu đề — mỗi dòng một bài', 'titles', '', 8 );
		self::input( 'Hoặc tải file Excel .xlsx / CSV (cột title)', 'matrix_file', '', 'file', 'accept=".xlsx,.csv"' );
		echo '<p class="description">Chỉ dùng số tiêu đề tương ứng với số bài đã chọn. Nội dung được viết bằng Markdown, hiển thị thành bài WordPress và lưu Markdown gốc bên dưới trình sửa bài. Bài dài phụ thuộc giới hạn output token của model; số từ/H2–H6 là mục tiêu, không bảo đảm chính xác tuyệt đối.</p>';
		self::check( 'Tôi xác nhận API có thể phát sinh chi phí', 'confirm_cost' );
		self::submit( '✍️ Bắt đầu viết bài' );
		echo '</div>';
	}

	/** The latest failures are actionable directly from the homepage. */
	private static function recent_errors() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Fresh factory diagnostics from trusted custom table.
		$rows = $wpdb->get_results( 'SELECT id,campaign_id,title,error FROM ' . Store::table( 'articles' ) . " WHERE status IN ('FAILED','BLOCKED') ORDER BY updated_at DESC LIMIT 8", ARRAY_A );
		if ( ! $rows ) { return; }
		echo '<div class="masf-panel"><h2>⚠️ Bài lỗi cần kiểm tra</h2><p>Nếu gặp lỗi API, xem nội dung lỗi và chọn Thử lại ở Đợt viết bài sau khi đã sửa API.</p><div class="masf-table"><table class="widefat striped"><thead><tr><th>Bài / Campaign</th><th>Lý do và cách xử lý</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td><a href="' . esc_url( admin_url( 'admin.php?page=masf-matrix&article=' . absint( $r['id'] ) ) ) . '">' . esc_html( $r['title'] ) . '</a><br>#' . absint( $r['campaign_id'] ) . '</td><td>' . esc_html( $r['error'] ) . '</td></tr>';
		}
		echo '</tbody></table></div></div>';
	}

	/**
	 * Campaign table.
	 *
	 * @return mixed Operation result.
	 */
	private static function campaign_table() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Schema/table names are trusted constants from Store::table(); all dynamic values are bound or handled by wpdb.
		$cs = $wpdb->get_results( 'SELECT * FROM ' . Store::table( 'campaigns' ) . ' ORDER BY id DESC LIMIT 100', ARRAY_A );
		echo '<div class="masf-table"><table class="widefat striped"><thead><tr><th>Chiến dịch</th><th>Trạng thái / Bài</th><th>Điều khiển</th></tr></thead><tbody>';
		foreach ( $cs as $c ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Schema/table names are trusted constants from Store::table(); all dynamic values are bound or handled by wpdb.
			$n = $wpdb->get_results( $wpdb->prepare( 'SELECT status,COUNT(*) n FROM ' . Store::table( 'articles' ) . ' WHERE campaign_id=%d GROUP BY status', $c['id'] ), ARRAY_A );
			echo '<tr><td><a href="' . esc_url( admin_url( 'admin.php?page=masf-campaigns&campaign=' . $c['id'] ) ) . '">#' . absint( $c['id'] ) . ' ' . esc_html( $c['name'] ) . '</a></td><td>' . esc_html( $c['status'] ) . '<br>' . esc_html(
				implode(
					', ',
					array_map(
						function ( $r ) {
							return $r['status'] . ': ' . $r['n'];
						},
						$n
					)
				)
			) . '</td><td>';
			foreach ( array(
				'start'  => 'Start',
				'pause'  => 'Pause',
				'resume' => 'Resume',
				'stop'   => 'Stop',
				'retry'  => 'Retry Failed',
				'batch'  => 'Process Next Batch',
			) as $op => $label ) {
				self::begin( $op, $c['id'] );
				echo '<button class="button" type="submit">' . esc_html( $label ) . '</button></form>'; }
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
	/**
	 * Campaigns.
	 *
	 * @return mixed Operation result.
	 */
	private static function campaigns() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filters after capability checks; all mutations use verified POST nonces.
		$id = absint( $_GET['campaign'] ?? 0 );
		$c  = $id ? Store::campaign( $id ) : null;
		if ( $id && ! $c ) {
			echo '<p>Không tìm thấy chiến dịch.</p>';
			return; }
		$d = $c ? $c['config'] : Settings::campaign_config( array() );
		echo '<div class="masf-panel"><h2>' . ( $c ? 'Cấu hình #' . absint( $id ) : 'Tạo chiến dịch mới' ) . '</h2><p>Sửa cấu hình khi chiến dịch đã Pause. Các bài đang có giữ nguyên checkpoint và brief đã nhập.</p>';
		self::begin( 'save_campaign', $id );
		echo '<div class="masf-grid">';
		self::input( 'Tên chiến dịch', 'name', $c['name'] ?? '', 'text', 'required maxlength="200"' );
		self::select(
			'Ngôn ngữ',
			'language',
			array(
				'vi' => 'Tiếng Việt',
				'en' => 'English',
				'zh' => '中文',
				'ja' => '日本語',
				'ko' => '한국어',
				'fr' => 'Français',
				'es' => 'Español',
			),
			$d['language']
		);
		self::select( 'Loại bài / search intent', 'intent', array_combine( array( 'Informational', 'Commercial', 'Comparison', 'How-to', 'Local SEO', 'FAQ / AEO' ), array( 'Thông tin', 'Thương mại', 'So sánh', 'Hướng dẫn', 'Local SEO', 'FAQ / AEO' ) ), $d['intent'] );
		echo '<label>Danh mục cha/con';
		wp_dropdown_categories(
			array(
				'name'         => 'category',
				'hide_empty'   => false,
				'hierarchical' => true,
				'selected'     => $d['category'],
			)
		);
		echo '</label>';
		$providers = array(
			'claude' => 'Anthropic Claude',
			'gemini' => 'Google Gemini',
			'openai' => 'OpenAI',
		);
		foreach ( array(
			'provider'        => 'AI viết chính',
			'fallback'        => 'AI dự phòng',
			'reviewer'        => 'AI kiểm duyệt (tùy chọn)',
			'repair_provider' => 'AI sửa bài (tùy chọn)',
		) as $field => $label ) {
			self::select( $label, $field, 'provider' === $field ? $providers : array( '' => 'Không dùng / dùng AI chính' ) + $providers, $d[ $field ] );
			$model_field = array(
				'provider'        => 'model',
				'fallback'        => 'fallback_model',
				'reviewer'        => 'reviewer_model',
				'repair_provider' => 'repair_model',
			)[ $field ];
			self::input( 'Model ID (' . $label . ') — trống dùng mặc định provider', $model_field, $d[ $model_field ] );
		}
		self::input( 'Số từ tối thiểu', 'min_words', $d['min_words'], 'number', 'min="1000" max="5000"' );
		self::input( 'Số từ tối đa', 'max_words', $d['max_words'], 'number', 'min="1000" max="5000"' );
		self::select(
			'Trạng thái giao bài',
			'mode',
			array(
				'draft'    => 'Draft — bản nháp',
				'pending'  => 'Pending — chờ duyệt',
				'publish'  => 'Tự đăng sau QA',
				'schedule' => 'Lên lịch sau QA',
			),
			$d['mode']
		);
		self::select(
			'Số bài mỗi lô',
			'batch_size',
			array(
				1  => 1,
				5  => 5,
				10 => 10,
				20 => 20,
			),
			$d['batch_size']
		);
		self::input( 'Tối đa bài/ngày (tính cả Draft đã giao)', 'daily_articles', $d['daily_articles'], 'number', 'min="1" max="500"' );
		self::input( 'Giới hạn worker (1–3; hosting nên 1)', 'concurrency', $d['concurrency'], 'number', 'min="1" max="3"' );
		self::input( 'Ngân sách toàn chiến dịch (USD, 0 = chưa giới hạn)', 'budget', $d['budget'], 'number', 'min="0" step="0.01"' );
		self::input( 'Ngưỡng QA', 'qa_threshold', $d['qa_threshold'], 'number', 'min="50" max="100"' );
		self::input( 'Số lần sửa nội dung tối đa', 'max_repairs', $d['max_repairs'], 'number', 'min="0" max="5"' );
		self::input( 'Bắt đầu lịch (múi giờ WordPress)', 'schedule_start', $d['schedule_start'], 'datetime-local' );
		self::input( 'Khoảng cách lịch (giờ)', 'schedule_interval', $d['schedule_interval'], 'number', 'min="1"' );
		self::input( 'Internal link targets: ID bài/trang đã publish, cách nhau dấu phẩy', 'internal_links', implode( ',', $d['internal_links'] ) );
		echo '</div>';
		self::area( 'Brief chung / nhóm chủ đề', 'brief', $d['brief'] );
		self::area( 'Nguồn xác minh: URL và trích đoạn/dữ liệu thực tế đi kèm (URL riêng chưa chứng minh nội dung)', 'sources', $d['sources'] );
		self::check( 'Tôi chủ động bật tự đăng/lên lịch cho chiến dịch này', 'auto_publish', $d['auto_publish'] );
		self::check( 'Bắt buộc AI kiểm duyệt trước khi giao bài', 'require_review', $d['require_review'] );
		self::submit( $id ? 'Lưu cấu hình' : 'Tạo chiến dịch ở trạng thái Pause' );
		echo '</div>';
		if ( $c ) {
			echo '<div class="masf-panel"><h2>Nhập tiêu đề / Excel</h2><p>Mỗi dòng một tiêu đề. Từ khóa được ghép theo thứ tự dòng. CSV/XLSX tối đa 5 MB, 10.000 dòng; worksheet đầu tiên. Cột: title, primary_keyword, secondary_keywords, brief, language, category, intent, sources, internal_links. Danh mục dùng ID hoặc tên đã tồn tại. Hàng trùng bị bỏ qua.</p>';
			self::begin( 'import', $id, true );
			self::area( 'Danh sách tiêu đề', 'titles', '', 6 );
			self::area( 'Danh sách primary keyword (tùy chọn)', 'keywords', '', 4 );
			self::input( 'Hoặc tải CSV UTF-8 / Excel .xlsx', 'matrix_file', '', 'file', 'accept=".csv,.xlsx"' );
			self::submit( 'Nhập vào Content Matrix' );
			echo '</div>';
			echo '<div class="masf-panel"><h2>AI đề xuất tiêu đề</h2><p>Tác vụ nền dùng API của chiến dịch. Đề xuất hợp lệ được kiểm tra trùng trước khi nhập. Thao tác này không tự khởi động viết bài.</p>';
			self::begin( 'plan', $id );
			self::area( 'Chủ đề và nhóm chủ đề', 'topic' );
			self::input( 'Số tiêu đề đề xuất (1–50)', 'count', 5, 'number', 'min="1" max="50"' );
			self::submit( 'Đề xuất và nhập tiêu đề mới' );
			echo '</div>';
		}
		self::campaign_table();
	}
	/**
	 * Providers.
	 *
	 * @return mixed Operation result.
	 */
	private static function providers() {
		echo '<p>Chỉ cần nhập khóa API và Model ID của AI muốn sử dụng, rồi bấm Lưu. Khóa được mã hóa ở máy chủ, không hiện lại trên trang và không gửi lên GitHub. Để trống ô khóa nếu đã lưu. Thuê bao ChatGPT/Claude/Gemini không thay thế API credit.</p>';
		foreach ( array( 'claude' => 'Claude (Anthropic)', 'gemini' => 'Gemini (Google)', 'openai' => 'GPT (OpenAI)' ) as $name => $label ) {
			$d = Settings::provider( $name );
			echo '<div class="masf-panel"><h2>' . esc_html( $label ) . '</h2><p>' . ( Settings::key( $name ) ? '✅ Có API key đã lưu' : '⚠️ Chưa có API key' ) . '</p>';
			self::begin( 'save_provider' );
			echo '<input type="hidden" name="provider" value="' . esc_attr( $name ) . '"><div class="masf-grid">';
			self::input( 'API key (được mã hóa, không hiển thị lại)', 'api_key', '', 'password', 'autocomplete="new-password" maxlength="500"' );
			self::input( 'Model ID', 'model', $d['model'], 'text', 'required maxlength="150" list="masf-models-' . esc_attr( $name ) . '"' );
			echo '<datalist id="masf-models-' . esc_attr( $name ) . '">';
			foreach ( get_transient( 'masf_models_' . $name ) ?: array() as $model ) { echo '<option value="' . esc_attr( $model ) . '"></option>'; }
			echo '</datalist></div>';
			self::check( 'Xóa API key đã lưu', 'remove_key' );
			self::submit( 'Lưu API & Model' );
			echo '<div class="masf-buttons"><button type="button" class="button masf-api" data-provider="' . esc_attr( $name ) . '" data-operation="models">Tìm model</button><button type="button" class="button masf-api" data-provider="' . esc_attr( $name ) . '" data-operation="test">Thử kết nối (tốn ít token)</button></div><p class="masf-result" role="status"></p></div>';
		}
	}

	/**
	 * Settings.
	 *
	 * @return mixed Operation result.
	 */
	private static function settings() {
		$d = Settings::global();
		echo '<div class="masf-panel"><h2>SEO & vận hành</h2>';
		self::begin( 'save_settings' );
		echo '<div class="masf-grid">';
		foreach ( array(
			'concurrency' => 'Worker toàn site (1–3)',
			'max_retries' => 'Retry API tối đa (0–6)',
			'log_days'    => 'Giữ log (7–365 ngày)',
			'title_min'   => 'SEO title: ký tự tối thiểu khuyến nghị',
			'title_max'   => 'SEO title: ký tự tối đa khuyến nghị',
			'meta_min'    => 'Meta: ký tự tối thiểu khuyến nghị',
			'meta_max'    => 'Meta: ký tự tối đa khuyến nghị',
		) as $field => $label ) {
			self::input( $label, $field, $d[ $field ], 'number', 'min="0"' ); }
		echo '</div>';
		self::submit();
		echo '</div><div class="masf-panel"><h2>Site / Brand Profile</h2><p>Nhập dữ liệu được chủ cửa hàng xác nhận cho website này. Plugin không lấy thương hiệu, NAP hoặc giá từ repo tham chiếu. Thay đổi profile ảnh hưởng các bài chưa giao, không sửa bài đã publish.</p>';
		self::begin( 'save_brand' );
		$b = Settings::brand();
		echo '<div class="masf-grid">';
		foreach ( array(
			'name'    => 'Tên thương hiệu',
			'address' => 'Địa chỉ',
			'phone'   => 'Điện thoại',
			'website' => 'Website',
			'maps'    => 'Google Maps',
			'hours'   => 'Giờ mở cửa',
		) as $field => $label ) {
			self::input( $label, $field, $b[ $field ] ?? '', in_array( $field, array( 'website', 'maps' ), true ) ? 'url' : 'text' ); }
		echo '</div>';
		foreach ( array(
			'prices'    => 'Bảng giá đã xác nhận',
			'deposit'   => 'Chính sách đặt cọc',
			'allowed'   => 'Nội dung được phép sử dụng / CTA có căn cứ',
			'forbidden' => 'Thông tin không được suy đoán / quy định nội dung',
		) as $field => $label ) {
			self::area( $label, $field, $b[ $field ] ?? '' );
		} self::submit( 'Lưu Brand Profile' );
		echo '</div>';
	}
	/**
	 * Matrix.
	 *
	 * @param mixed $page Page.
	 *
	 * @return mixed Operation result.
	 */
	private static function matrix( $page ) {
		global $wpdb;
		$t = Store::table( 'articles' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filters after capability checks; all mutations use verified POST nonces.
		$campaign = absint( $_GET['campaign'] ?? 0 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filters after capability checks; all mutations use verified POST nonces.
		$status = sanitize_key( $_GET['status'] ?? '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filters after capability checks; all mutations use verified POST nonces.
		$number = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$where  = '1=1';
		$args   = array();
		if ( $campaign ) {
			$where .= ' AND campaign_id=%d';
			$args[] = $campaign; }
		if ( $status && in_array( strtoupper( $status ), array( 'PLANNED', 'WRITING', 'QA', 'REPAIR', 'READY', 'PUBLISHED', 'FAILED', 'BLOCKED' ), true ) ) {
			$where .= ' AND status=%s';
			$args[] = strtoupper( $status ); }
		if ( 'masf-queue' === $page ) {
			$where .= " AND completed_at IS NULL AND status NOT IN ('PUBLISHED','FAILED','BLOCKED')"; }
		if ( 'masf-qa' === $page ) {
			$where .= ' AND qa_report IS NOT NULL'; }
		if ( 'masf-schedule' === $page ) {
			$where .= " AND delivery_status='future'"; }
		$query  = "SELECT * FROM $t WHERE $where ORDER BY id DESC LIMIT 50 OFFSET %d";
		$args[] = ( $number - 1 ) * 50;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Schema/table names are trusted constants from Store::table(); all dynamic values are bound or handled by wpdb.
		$rows = $wpdb->get_results( $wpdb->prepare( $query, $args ), ARRAY_A );
		echo '<form method="get" class="masf-filter"><input type="hidden" name="page" value="' . esc_attr( $page ) . '">';
		self::input( 'Campaign ID (0=tất cả)', 'campaign', $campaign, 'number', 'min="0"' );
		self::select( 'Trạng thái', 'status', array( '' => 'Tất cả' ) + array_combine( array( 'PLANNED', 'WRITING', 'QA', 'REPAIR', 'READY', 'PUBLISHED', 'FAILED', 'BLOCKED' ), array( 'PLANNED', 'WRITING', 'QA', 'REPAIR', 'READY', 'PUBLISHED', 'FAILED', 'BLOCKED' ) ), strtoupper( $status ) );
		echo '<button class="button">Lọc</button></form>';
		echo '<div class="masf-table"><table class="widefat striped"><thead><tr><th>ID / Chiến dịch</th><th>Tiêu đề / Keyword</th><th>AI / QA</th><th>Trạng thái</th><th>Post / Lỗi</th></tr></thead><tbody>';
		foreach ( $rows as $a ) {
			echo '<tr><td><a href="' . esc_url( admin_url( 'admin.php?page=masf-matrix&article=' . $a['id'] ) ) . '">#' . absint( $a['id'] ) . '</a><br>C' . absint( $a['campaign_id'] ) . '</td><td>' . esc_html( $a['title'] ) . '<br><small>' . esc_html( $a['primary_keyword'] ) . '</small></td><td>' . esc_html( $a['provider'] . ' / ' . $a['model'] ) . '<br>QA: ' . absint( $a['qa_score'] ) . '</td><td>' . esc_html( $a['status'] . ' / ' . $a['phase'] . ' / ' . $a['delivery_status'] ) . '<br>Retry ' . absint( $a['retry_count'] ) . ', sửa ' . absint( $a['repair_count'] ) . '</td><td>';
			if ( $a['post_id'] ) {
				echo '<a href="' . esc_url( get_edit_post_link( $a['post_id'] ) ) . '">Sửa bài #' . absint( $a['post_id'] ) . '</a>'; }
			if ( 'masf-schedule' === $page && $a['post_id'] ) {
				echo '<br>' . esc_html( get_post_field( 'post_date', $a['post_id'] ) ); }
			echo '<br>' . esc_html( mb_substr( $a['error'] ?? '', 0, 240 ) ) . '<br><small>UTC: ' . esc_html( $a['updated_at'] ) . '</small></td></tr>';
		}
		echo '</tbody></table></div><p>Trang ' . absint( $number ) . ' · <a href="' . esc_url( add_query_arg( 'paged', max( 1, $number - 1 ) ) ) . '">Trước</a> · <a href="' . esc_url( add_query_arg( 'paged', $number + 1 ) ) . '">Tiếp</a></p>';
	}
	/**
	 * Detail.
	 *
	 * @param int $id Id.
	 *
	 * @return mixed Operation result.
	 */
	private static function detail( $id ) {
		$a = Store::article( $id );
		if ( ! $a ) {
			echo '<p>Bài không tồn tại.</p>';
			return; }
		echo '<div class="masf-panel"><h2>' . esc_html( $a['title'] ) . '</h2><p>Article UUID: ' . esc_html( $a['uuid'] ) . ' · Campaign #' . absint( $a['campaign_id'] ) . '</p><p>Trạng thái: ' . esc_html( $a['status'] . ' / ' . $a['phase'] ) . '</p><h3>Brief / Matrix</h3><pre>' . esc_html( wp_json_encode( $a['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre><h3>QA</h3><pre>' . esc_html( wp_json_encode( $a['qa_report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre><p>' . esc_html( $a['error'] ?? '' ) . '</p>';
		if ( $a['post_id'] ) {
			echo '<a class="button" href="' . esc_url( get_edit_post_link( $a['post_id'] ) ) . '">Mở bản nháp / bài WordPress</a>'; }
		echo '</div>';
	}
	/**
	 * Logs.
	 *
	 * @return mixed Operation result.
	 */
	private static function logs() {
		global $wpdb;
		echo '<div class="masf-panel"><h2>Xuất báo cáo</h2><p>CSV Matrix và JSON report không có API key hoặc request headers.</p>';
		self::begin( 'export' );
		self::select(
			'Định dạng',
			'format',
			array(
				'csv'  => 'Content Matrix CSV UTF-8',
				'json' => 'Report JSON',
			),
			'csv'
		);
		self::submit( 'Tải báo cáo' );
		echo '</div>';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Schema/table names are trusted constants from Store::table(); all dynamic values are bound or handled by wpdb.
		$rows = $wpdb->get_results( 'SELECT * FROM ' . Store::table( 'logs' ) . ' ORDER BY id DESC LIMIT 200', ARRAY_A );
		echo '<div class="masf-table"><table class="widefat striped"><thead><tr><th>UTC</th><th>C / Bài / Level</th><th>Nội dung</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( $r['created_at'] ) . '</td><td>' . esc_html( $r['campaign_id'] . ' / ' . $r['article_id'] . ' / ' . $r['level'] ) . '</td><td>' . esc_html( $r['message'] ) . '</td></tr>';
		} echo '</tbody></table></div>';
	}
	/**
	 * Handle.
	 *
	 * @return mixed Operation result.
	 * @throws \InvalidArgumentException If the operation cannot complete.
	 * @throws \RuntimeException If the operation cannot complete.
	 */
	public static function handle() {
		if ( ! self::permission() ) {
			wp_die( esc_html( 'Không có quyền.' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'masf_action' );
		$d       = wp_unslash( $_POST );
		$op      = sanitize_key( $d['op'] ?? '' );
		$id      = absint( $d['campaign_id'] ?? 0 );
		$page    = 'masf';
		$message = 'Đã lưu.';
		global $wpdb;
		try {
			if ( 'quick_create' === $op ) {
				$created = self::create_quick( $d );
				$id = $created['id'];
				$message = $created['message'];
				$page = 'masf';
			} elseif ( in_array( $op, array( 'start', 'pause', 'resume', 'stop', 'retry', 'batch' ), true ) ) {
				Queue::control( $id, $op );
				$message = 'Đã nhận lệnh ' . $op . '. Tác vụ đang chạy sẽ lưu checkpoint; bước tiếp theo theo trạng thái mới.'; } elseif ( 'save_campaign' === $op ) {
				$name = mb_substr( sanitize_text_field( $d['name'] ?? '' ), 0, 200 );
				if ( ! $name ) {
					throw new \InvalidArgumentException( 'Nhập tên chiến dịch.' ); }
				$config = Settings::campaign_config( $d );
				$term   = get_term( $config['category'], 'category' );
				if ( ! $term || is_wp_error( $term ) ) {
					throw new \InvalidArgumentException( 'Chọn danh mục hợp lệ.' ); }
				if ( $id ) {
					$c = Store::campaign( $id );
					if ( ! $c || in_array( $c['status'], array( 'RUNNING', 'BATCH' ), true ) ) {
						throw new \InvalidArgumentException( 'Pause chiến dịch trước khi sửa cấu hình.' );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache.
					} $wpdb->update(
						Store::table( 'campaigns' ),
						array(
							'name'       => $name,
							'config'     => wp_json_encode( $config ),
							'updated_at' => Store::now(),
						),
						array( 'id' => $id )
					); } else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Factory checkpoints require direct custom-table operations.
					$ok = $wpdb->insert(
						Store::table( 'campaigns' ),
						array(
							'name'       => $name,
							'status'     => 'PAUSED',
							'config'     => wp_json_encode( $config ),
							'created_by' => get_current_user_id(),
							'created_at' => Store::now(),
							'updated_at' => Store::now(),
						)
					);
					if ( ! $ok ) {
						throw new \RuntimeException( 'Không lưu được chiến dịch.' );
					} $id = $wpdb->insert_id; }
								$page = 'masf-campaigns';
				} elseif ( 'import' === $op ) {
					$rows     = array();
					$titles   = preg_split( '/\r\n|\r|\n/', $d['titles'] ?? '' );
					$keywords = preg_split( '/\r\n|\r|\n/', $d['keywords'] ?? '' );
					foreach ( $titles as $i => $title ) {
						if ( trim( $title ) ) {
										$rows[] = array(
											'title' => $title,
											'primary_keyword' => $keywords[ $i ] ?? '',
										); }
					}
					if ( ! empty( $_FILES['matrix_file']['name'] ) ) {
						// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Importer::upload validates PHP-uploaded temp path, size, extension and file structure; no client path is trusted.
						$rows = array_merge( $rows, Importer::upload( $_FILES['matrix_file'] ) ); }
					if ( count( $rows ) > Importer::MAX_ROWS || ! $rows ) {
						throw new \InvalidArgumentException( 'Nhập 1–10.000 tiêu đề.' ); }
					$r       = Importer::add( $id, $rows );
					$message = 'Đã thêm ' . $r['added'] . ' bài. Bỏ qua ' . count( $r['skipped'] ) . ' dòng. ' . implode( '; ', array_slice( $r['skipped'], 0, 8 ) );
					$page    = 'masf-campaigns';
				} elseif ( 'plan' === $op ) {
					$c = Store::campaign( $id );
					if ( ! $c ) {
								throw new \InvalidArgumentException( 'Không tìm thấy chiến dịch.' ); }
					if ( as_has_scheduled_action( 'masf_plan', array( $id ), Queue::GROUP ) || ! empty( $c['config']['planning'] ) ) {
						throw new \InvalidArgumentException( 'Đã có tác vụ đề xuất đang chờ.' ); }
					$topic = mb_substr( sanitize_textarea_field( $d['topic'] ?? '' ), 0, 8000 );
					if ( ! $topic ) {
							throw new \InvalidArgumentException( 'Nhập chủ đề.' ); }
					$c['config']['planning'] = array(
						'topic' => $topic,
						'count' => max( 1, min( 50, absint( $d['count'] ?? 5 ) ) ),
					);
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache.
					$wpdb->update( Store::table( 'campaigns' ), array( 'config' => wp_json_encode( $c['config'] ) ), array( 'id' => $id ) );
					as_enqueue_async_action( 'masf_plan', array( $id ), Queue::GROUP, true );
					$message = 'Đã xếp tác vụ AI đề xuất tiêu đề vào hàng đợi.';
					$page    = 'masf-campaigns';
				} elseif ( 'save_provider' === $op ) {
					$name = sanitize_key( $d['provider'] ?? '' );
					if ( ! in_array( $name, array( 'claude', 'gemini', 'openai' ), true ) ) {
								throw new \InvalidArgumentException( 'Provider không hợp lệ.' ); }
					$p = Settings::provider( $name );
					$p['model'] = Settings::model( $d['model'] ?? '' );
					$p['daily_tokens'] = max( 6000000, (int) $p['daily_tokens'] );
					$key = trim( $d['api_key'] ?? '' );
					if ( $key && ( strlen( $key ) > 500 || preg_match( '/[\s\x00-\x1f]/', $key ) ) ) {
							throw new \InvalidArgumentException( 'API key có ký tự không hợp lệ.' ); }
					if ( ! empty( $d['remove_key'] ) ) {
						delete_option( 'masf_key_' . $name );
					} elseif ( $key ) {
						update_option( 'masf_key_' . $name, Settings::encrypt( $key ), false ); }
					update_option( 'masf_provider_' . $name, $p, false );
					$page = 'masf-providers';
				} elseif ( 'save_settings' === $op ) {
					$g = array(
						'concurrency' => max( 1, min( 3, absint( $d['concurrency'] ?? 1 ) ) ),
						'max_retries' => min( 6, absint( $d['max_retries'] ?? 4 ) ),
						'log_days'    => max( 7, min( 365, absint( $d['log_days'] ?? 30 ) ) ),
						'title_min'   => max( 10, min( 150, absint( $d['title_min'] ?? 50 ) ) ),
						'title_max'   => max( 10, min( 200, absint( $d['title_max'] ?? 75 ) ) ),
						'meta_min'    => max( 50, min( 300, absint( $d['meta_min'] ?? 120 ) ) ),
						'meta_max'    => max( 50, min( 500, absint( $d['meta_max'] ?? 180 ) ) ),
					);
					if ( $g['title_min'] > $g['title_max'] || $g['meta_min'] > $g['meta_max'] ) {
								throw new \InvalidArgumentException( 'Khoảng ký tự tối thiểu/tối đa không hợp lệ.' );
					} update_option( 'masf_settings', $g, false );
					$page = 'masf-settings';
				} elseif ( 'save_brand' === $op ) {
					$b = array();
					foreach ( array( 'name', 'address', 'phone', 'website', 'maps', 'hours', 'prices', 'deposit', 'allowed', 'forbidden' ) as $field ) {
								$b[ $field ] = in_array( $field, array( 'website', 'maps' ), true ) ? esc_url_raw( $d[ $field ] ?? '', array( 'https', 'http' ) ) : mb_substr( sanitize_textarea_field( $d[ $field ] ?? '' ), 0, 12000 );
					} update_option( 'masf_brand', $b, false );
					$page = 'masf-settings';
				} elseif ( 'export' === $op ) {
					self::export( sanitize_key( $d['format'] ?? 'csv' ) ); } else {
								throw new \InvalidArgumentException( 'Lệnh không được hỗ trợ.' ); }
		} catch ( \Throwable $e ) {
			$message = $e instanceof \InvalidArgumentException || $e instanceof API_Error ? $e->getMessage() : 'Không hoàn tất thao tác; kiểm tra dữ liệu/môi trường PHP.'; }
		set_transient( 'masf_notice_' . get_current_user_id(), $message, 120 );
		$url = admin_url( 'admin.php?page=' . $page );
		if ( 'masf-campaigns' === $page && $id ) {
			$url = add_query_arg( 'campaign', $id, $url );
		} wp_safe_redirect( $url );
		exit;
	}
	/**
	 * Atomic-ish fast creation: all user input validated before API key
	 * persistence and campaign insertion. No external API call in the POST.
	 *
	 * @param array $d Uns lashed authenticated POST fields.
	 * @return array Campaign ID and status message.
	 */
	private static function create_quick( $d ) {
		global $wpdb;
		if ( empty( $d['confirm_cost'] ) ) { throw new \InvalidArgumentException( 'Bạn cần xác nhận khả năng phát sinh phí API.' ); }
		$provider = sanitize_key( $d['provider'] ?? '' );
		if ( ! in_array( $provider, array( 'claude', 'gemini', 'openai' ), true ) ) { throw new \InvalidArgumentException( 'Chọn AI hợp lệ.' ); }
		$cfg_provider = Settings::provider( $provider );
		if ( ! Settings::key( $provider ) || ! $cfg_provider['model'] ) { throw new \InvalidArgumentException( 'Vào Cài đặt API, nhập khóa và Model ID của AI đã chọn rồi lưu trước.' ); }
		$count = absint( $d['count'] ?? 0 );
		$words = absint( $d['words'] ?? 0 );
		if ( $count < 1 || $count > 100 || $words < 1500 || $words > 10000 ) { throw new \InvalidArgumentException( 'Chọn 1–100 bài và 1.500–10.000 từ.' ); }
		$mode = sanitize_key( $d['mode'] ?? 'draft' );
		if ( ! in_array( $mode, array( 'draft', 'publish' ), true ) ) { throw new \InvalidArgumentException( 'Chọn trạng thái bài hợp lệ.' ); }
		if ( 'publish' === $mode && ! current_user_can( 'publish_posts' ) ) { throw new \InvalidArgumentException( 'Tài khoản không có quyền xuất bản.' ); }
		$cat = absint( $d['category'] ?? 0 );
		$term = get_term( $cat, 'category' );
		if ( ! $term || is_wp_error( $term ) ) { throw new \InvalidArgumentException( 'Danh mục cha/con không hợp lệ.' ); }
		$headings = array();
		foreach ( array( 'h2', 'h3', 'h4', 'h5', 'h6' ) as $tag ) {
			$headings[ $tag ] = absint( $d[ $tag ] ?? 0 );
			if ( $headings[ $tag ] > 20 ) { throw new \InvalidArgumentException( 'Mỗi loại H2–H6 tối đa 20.' ); }
		}
		if ( $headings['h2'] < 1 || array_sum( $headings ) > 60 ) { throw new \InvalidArgumentException( 'H2 ít nhất 1 và tổng H2–H6 tối đa 60.' ); }
		$titles = preg_split( '/\r\n|\r|\n/', (string) ( $d['titles'] ?? '' ) );
		$rows = array();
		foreach ( $titles as $title ) { if ( trim( $title ) ) { $rows[] = array( 'title' => $title ); } }
		if ( ! empty( $_FILES['matrix_file']['name'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Importer validates uploaded file structure.
			$rows = array_merge( $rows, Importer::upload( $_FILES['matrix_file'] ) );
		}
		if ( count( $rows ) < $count ) { throw new \InvalidArgumentException( 'Chọn ' . $count . ' bài nhưng mới có ' . count( $rows ) . ' tiêu đề.' ); }
		$rows = array_slice( $rows, 0, $count );
		$config = Settings::campaign_config( array(
			'simple_mode' => 1, 'provider' => $provider, 'model' => $cfg_provider['model'],
			'language' => sanitize_text_field( $d['language'] ?? 'vi' ), 'category' => $cat,
			'min_words' => max( 1500, (int) floor( $words * 0.90 ) ),
			'max_words' => min( 10000, (int) ceil( $words * 1.10 ) ),
			'h2' => $headings['h2'], 'h3' => $headings['h3'], 'h4' => $headings['h4'], 'h5' => $headings['h5'], 'h6' => $headings['h6'],
			'mode' => $mode, 'auto_publish' => 'publish' === $mode ? 1 : 0,
			'batch_size' => 20, 'daily_articles' => $count, 'max_repairs' => 0,
		) );
		$config['target_words'] = $words;
		// Larger requests need more output tokens; the provider may enforce a smaller cap.
		$config['output_tokens'] = max( 12000, min( 64000, (int) ceil( $words * 3.5 ) ) );
		$name = 'Viết nhanh ' . wp_date( 'Y-m-d H:i' ) . ' · ' . $provider;
		$ok = $wpdb->insert( Store::table( 'campaigns' ), array(
			'name' => $name, 'status' => 'PAUSED', 'config' => wp_json_encode( $config ),
			'created_by' => get_current_user_id(), 'created_at' => Store::now(), 'updated_at' => Store::now(),
		) );
		if ( ! $ok ) { throw new \RuntimeException( 'Không tạo được đợt viết bài.' ); }
		$id = (int) $wpdb->insert_id;
		$result = Importer::add( $id, $rows );
		if ( ! $result['added'] ) { throw new \InvalidArgumentException( 'Tiêu đề trùng hoặc không hợp lệ. Đợt đã được lưu ở trạng thái tạm dừng.' ); }
		Queue::control( $id, 'start' );
		return array( 'id' => $id, 'message' => 'Đã đưa ' . $result['added'] . ' bài vào hàng đợi. Chế độ: ' . ( 'publish' === $mode ? 'tự xuất bản' : 'lưu nháp' ) . '. Có thể theo dõi ở Danh sách bài; AI sẽ viết Markdown từng bài.' );
	}

	/**
	 * Export.
	 *
	 * @param string $format Format.
	 *
	 * @return mixed Operation result.
	 */
	private static function export( $format ) {
		global $wpdb;
		nocache_headers();
		if ( 'json' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="masf-report.json"' );
			echo wp_json_encode(
				array(
					'version'      => MASF_VERSION,
					'generated_at' => Store::now(),
					'stats'        => self::stats(),
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Schema/table names are trusted constants from Store::table(); all dynamic values are bound or handled by wpdb.
					'logs'         => $wpdb->get_results( 'SELECT * FROM ' . Store::table( 'logs' ) . ' ORDER BY id DESC LIMIT 500', ARRAY_A ),
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
			);
			exit; }
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="masf-content-matrix.csv"' );
		$f = fopen( 'php://output', 'w' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing only bounded php://temp or php://output streams.
		fwrite( $f, "\xEF\xBB\xBF" );
		$fields = array( 'id', 'uuid', 'campaign_id', 'title', 'primary_keyword', 'secondary_keywords', 'intent', 'brief', 'language', 'category', 'internal_links', 'min_words', 'max_words', 'provider', 'model', 'qa_score', 'status', 'post_id', 'published_url', 'retry_count', 'repair_count', 'error', 'updated_at' );
		fputcsv( $f, $fields, ',', '"', '' );
		$last = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Schema/table names are trusted constants from Store::table(); all dynamic values are bound or handled by wpdb.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Store::table( 'articles' ) . ' WHERE id>%d ORDER BY id LIMIT 200', $last ), ARRAY_A );
			foreach ( $rows as $a ) {
				// phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Intentional empty-value fallback; plugin requires PHP 8.1+.
				$payload = json_decode( $a['payload'], true ) ?: array();
				$row     = array();
				foreach ( $fields as $field ) {
					$value = $a[ $field ] ?? $payload[ $field ] ?? '';
					if ( is_array( $value ) ) {
							$value = implode( ';', $value );
					} $value = (string) $value;
					if ( preg_match( '/^[=+@\-\t\r]/', $value ) ) {
								$value = "'" . $value;
					} $row[] = $value;
				} fputcsv( $f, $row, ',', '"', '' );
				$last = $a['id'];
			}
		// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- Pagination loop deliberately uses the last fetched batch size.
		} while ( count( $rows ) === 200 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing temporary/output streams, not arbitrary files.
		fclose( $f );
		exit;
	}
}
