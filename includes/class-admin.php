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
		'masf-matrix'    => 'Danh sách bài / Matrix',
		'masf-queue'     => 'Hàng đợi / Queue',
		'masf-qa'        => 'SEO & QA',
		'masf-schedule'  => 'Lịch xuất bản',
		'masf-providers' => 'API Providers',
		'masf-settings'  => 'Cài đặt',
		'masf-logs'      => 'Logs & Reports',
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
		echo '<div class="masf-cards" id="masf-summary">';
		foreach ( $s['counts'] as $status => $n ) {
			echo '<div class="masf-card"><span>' . esc_html( $status ) . '</span><strong data-status="' . esc_attr( $status ) . '">' . absint( $n ) . '</strong></div>'; }
		echo '</div><div class="masf-panel"><p>Tổng: <b>' . absint( $s['total'] ) . '</b> · Queue: ' . absint( $s['queue'] ) . ' · Đã giao bài: ' . absint( $s['completed'] ) . ' · Tiến độ <b id="masf-progress">' . esc_html( $s['progress'] ) . '%</b></p><progress max="100" value="' . esc_attr( $s['progress'] ) . '"></progress>';
		// phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Intentional empty-value fallback; plugin requires PHP 8.1+.
		echo '<p>Input/output token: ' . esc_html( $s['usage']['input_tokens'] . '/' . $s['usage']['output_tokens'] ) . ' · Chi phí ước tính USD: ' . esc_html( $s['usage']['cost'] ) . ' · Dự phòng chưa xác nhận: ' . esc_html( $s['usage']['reserved_cost'] ) . '</p><p>Lần xử lý gần nhất (UTC): ' . esc_html( $s['last_processed'] ?: 'chưa chạy' ) . '</p>';
		echo '<p>Worker đang xử lý: ' . esc_html( $s['active'] ? wp_json_encode( $s['active'], JSON_UNESCAPED_UNICODE ) : 'không có' ) . '</p><p>Chi phí dựa trên giá token quản trị viên nhập; giá bằng 0 nghĩa là chưa có ước tính. READY có thể là bản nháp, chờ duyệt hoặc đã lên lịch.</p></div>';
		self::recent_errors();
		self::campaign_table();
		echo '<div class="masf-panel"><h2>Tự động nền</h2><p>Action Scheduler chạy từng bước, không cần mở Dashboard. WP-Cron cần lượt truy cập; xem INSTALLATION.md để đặt real cron tùy chọn trên Hostinger. Mặc định một worker. Lô là số bài cần xử lý, không phải số API request đồng thời.</p></div>';
	}
	/**
	 * The common path: one form, one campaign, bounded article count.
	 * API keys are write-only; no balance is inferred from a successful test.
	 */
	private static function quick_form() {
		$provider_labels = array( 'claude' => 'Claude (Anthropic)', 'gemini' => 'Gemini (Google)', 'openai' => 'OpenAI' );
		$chosen = 'claude';
		foreach ( $provider_labels as $name => $label ) {
			if ( Settings::key( $name ) && Settings::provider( $name )['model'] ) {
				$chosen = $name;
				break;
			}
		}
		$preferred = sanitize_key( (string) get_option( 'masf_last_quick_provider', '' ) );
		if ( isset( $provider_labels[ $preferred ] ) ) { $chosen = $preferred; }
		$defaults = Settings::provider( $chosen );
		$defaults['model'] = get_option( 'masf_last_quick_model_' . $chosen, $defaults['model'] );
		echo '<div class="masf-panel masf-quick"><h2>🚀 Tạo 1–100 bài SEO trong một màn hình</h2>';
		echo '<p>Chọn AI, API key, model, danh mục, số bài, độ dài và tiêu đề. Khi nhấn chạy, plugin tạo bài <strong>Draft</strong> trong WordPress qua hàng đợi nền; không tự đăng.</p>';
		self::begin( 'quick_create', 0, true );
		echo '<div class="masf-grid">';
		self::select( '1. Chọn AI', 'provider', $provider_labels, $chosen );
		self::input( '2. API key mới (bỏ trống nếu đã lưu)', 'api_key', '', 'password', 'autocomplete="new-password" maxlength="500"' );
		echo '<p class="masf-hint masf-key-status">';
		foreach ( $provider_labels as $name => $label ) {
			echo '<span data-key-provider="' . esc_attr( $name ) . '" class="' . ( $name === $chosen ? '' : 'masf-hidden' ) . '">' . esc_html( $label . ': ' . ( Settings::key( $name ) ? '✅ đã lưu key' : 'chưa có key' ) ) . '</span>';
		}
		echo '</p>';
		echo '<label class="masf-check"><input id="masf-show-key" type="checkbox"> Hiển thị key đang nhập (không hiện key đã lưu)</label>';
		echo '<label>3. Model ID (tùy tài khoản) <input type="text" name="model" required maxlength="150" value="' . esc_attr( $defaults['model'] ) . '" placeholder="Nhập model ID từ tài khoản API" id="masf-quick-model" list="masf-quick-models"></label>';
		echo '<datalist id="masf-quick-models">';
		foreach ( get_transient( 'masf_models_' . $chosen ) ?: array() as $m ) {
			echo '<option value="' . esc_attr( $m ) . '"></option>';
		}
		echo '</datalist>';
		self::input( '4. Số bài (1–100)', 'count', 5, 'number', 'required min="1" max="100" step="1"' );
		echo '<label>5. Danh mục bài viết';
		wp_dropdown_categories( array( 'name' => 'category', 'hide_empty' => false, 'hierarchical' => true, 'selected' => (int) get_option( 'default_category' ) ) );
		echo '</label>';
		self::input( '6. Độ dài mục tiêu (1.000–5.000 từ)', 'words', 1500, 'number', 'required min="1000" max="5000" step="100"' );
		self::select( '7. Ngôn ngữ', 'language', array( 'vi' => 'Tiếng Việt', 'en' => 'English', 'es' => 'Español', 'fr' => 'Français', 'zh' => '中文', 'ko' => '한국어', 'ja' => '日本語' ), 'vi' );
		self::select( '8. Output tokens / yêu cầu', 'output_tokens', array( '4000' => '4.000 (bài ngắn)', '8000' => '8.000', '16000' => '16.000 (khuyến nghị)', '32000' => '32.000', '64000' => '64.000 (chỉ khi model hỗ trợ)' ), (string) ( in_array( (int) $defaults['output_tokens'], array( 4000, 8000, 16000, 32000, 64000 ), true ) ? $defaults['output_tokens'] : 16000 ) );
		echo '</div>';
		self::area( '9. Tiêu đề (mỗi dòng một bài) — hoặc nhập Excel/CSV', 'titles', '', 7 );
		self::input( 'File Excel .xlsx hoặc CSV chứa cột title / tiêu đề', 'matrix_file', '', 'file', 'accept=".xlsx,.csv"' );
		echo '<p class="description">Có thể kết hợp tiêu đề viết tay và file. Số bài phải ≤ số tiêu đề; chỉ lấy số dòng đầu tiên đã chọn. Bỏ qua tiêu đề trùng đã tồn tại. Khi tài khoản API hết credit, plugin dừng chiến dịch và hiện lý do. Không thể biết chính xác số dư API chỉ từ key.</p>';
		self::check( 'Tôi đồng ý thực hiện các yêu cầu API có thể phát sinh phí (chỉ tạo Draft)', 'confirm_cost' );
		self::submit( '🚀 Tạo chiến dịch và chạy viết bài' );
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=masf-providers' ) ) . '">Cấu hình API nâng cao / Test Connection</a> · <a href="' . esc_url( admin_url( 'admin.php?page=masf-campaigns' ) ) . '">Quản lý chiến dịch</a></p></div>';
	}

	/** The latest failures are actionable directly from the homepage. */
	private static function recent_errors() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Fresh factory diagnostics from trusted custom table.
		$rows = $wpdb->get_results( 'SELECT id,campaign_id,title,error FROM ' . Store::table( 'articles' ) . " WHERE status IN ('FAILED','BLOCKED') ORDER BY updated_at DESC LIMIT 8", ARRAY_A );
		if ( ! $rows ) { return; }
		echo '<div class="masf-panel"><h2>⚠️ Bài lỗi cần kiểm tra</h2><p>FAILED không có nghĩa là API hết tiền. Xem nguyên nhân từng bài, sửa rồi chọn Retry Failed → Resume.</p><div class="masf-table"><table class="widefat striped"><thead><tr><th>Bài / Campaign</th><th>Lý do và cách xử lý</th></tr></thead><tbody>';
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
		echo '<p>Khóa chỉ lưu phía máy chủ; ô trống giữ khóa cũ. Lấy catalog từ API rồi chọn model và lưu. OpenAI catalog có thể gồm model không hỗ trợ Responses; Test Connection xác minh model đã chọn. Không có model cũ được hardcode.</p>';
		foreach ( array(
			'claude' => 'Anthropic Claude — Messages API',
			'gemini' => 'Google Gemini — AI Studio API',
			'openai' => 'OpenAI — Responses API',
		) as $name => $label ) {
			$d = Settings::provider( $name );
			echo '<div class="masf-panel"><h2>' . esc_html( $label ) . '</h2><p>Khóa: ' . ( Settings::key( $name ) ? 'đã cấu hình' : 'chưa có / cần nhập lại' ) . '</p>';
			self::begin( 'save_provider' );
			echo '<input type="hidden" name="provider" value="' . esc_attr( $name ) . '"><div class="masf-grid">';
			self::input( 'API key mới (không hiển thị lại)', 'api_key', '', 'password', 'autocomplete="new-password" maxlength="500"' );
			self::input( 'Model ID mặc định', 'model', $d['model'], 'text', 'list="masf-models-' . esc_attr( $name ) . '"' );
			echo '<datalist id="masf-models-' . esc_attr( $name ) . '">';
			// phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Intentional empty-value fallback; plugin requires PHP 8.1+.
			foreach ( get_transient( 'masf_models_' . $name ) ?: array() as $model ) {
				echo '<option value="' . esc_attr( $model ) . '"></option>';
			} echo '</datalist>';
			self::input( 'Output token tối đa/request', 'output_tokens', $d['output_tokens'], 'number', 'min="512" max="64000"' );
			self::input( 'Timeout giây (10–60)', 'timeout', $d['timeout'], 'number', 'min="10" max="60"' );
			self::input( 'Request/phút cục bộ', 'rpm', $d['rpm'], 'number', 'min="1" max="60"' );
			self::input( 'Token/ngày (UTC)', 'daily_tokens', $d['daily_tokens'], 'number', 'min="1000"' );
			self::input( 'USD / 1 triệu input token (model đang dùng)', 'input_price', $d['input_price'], 'number', 'min="0" step="0.0001"' );
			self::input( 'USD / 1 triệu output token', 'output_price', $d['output_price'], 'number', 'min="0" step="0.0001"' );
			echo '</div>';
			self::check( 'Xóa khóa đã lưu của provider này', 'remove_key' );
			self::submit( 'Lưu API Provider' );
			echo '<div class="masf-buttons"><button class="button masf-api" data-provider="' . esc_attr( $name ) . '" data-operation="models">Lấy danh sách model</button><button class="button masf-api" data-provider="' . esc_attr( $name ) . '" data-operation="test">Test Connection (có dùng token)</button></div><p class="masf-result" role="status"></p></div>';
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
					$p   = array(
						'model'         => Settings::model( $d['model'] ?? '' ),
						'output_tokens' => max( 512, min( 64000, absint( $d['output_tokens'] ?? 16000 ) ) ),
						'timeout'       => max( 10, min( 60, absint( $d['timeout'] ?? 45 ) ) ),
						'rpm'           => max( 1, min( 60, absint( $d['rpm'] ?? 6 ) ) ),
						'daily_tokens'  => max( 1000, absint( $d['daily_tokens'] ?? 1000000 ) ),
						'input_price'   => max( 0, (float) ( $d['input_price'] ?? 0 ) ),
						'output_price'  => max( 0, (float) ( $d['output_price'] ?? 0 ) ),
					);
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
		if ( empty( $d['confirm_cost'] ) ) {
			throw new \InvalidArgumentException( 'Xác nhận phí API trước khi chạy.' );
		}
		$provider = sanitize_key( $d['provider'] ?? '' );
		if ( ! in_array( $provider, array( 'claude', 'gemini', 'openai' ), true ) ) {
			throw new \InvalidArgumentException( 'Chọn nhà cung cấp API hợp lệ.' );
		}
		$count = absint( $d['count'] ?? 0 );
		$words = absint( $d['words'] ?? 0 );
		if ( $count < 1 || $count > 100 || $words < 1000 || $words > 5000 ) {
			throw new \InvalidArgumentException( 'Chọn 1–100 bài và 1.000–5.000 từ.' );
		}
		$model = Settings::model( $d['model'] ?? '' );
		if ( ! $model ) {
			throw new \InvalidArgumentException( 'Nhập Model ID để tránh gọi sai API.' );
		}
		$key = trim( (string) ( $d['api_key'] ?? '' ) );
		if ( $key && ( strlen( $key ) > 500 || preg_match( '/[\s\x00-\x1f]/', $key ) ) ) {
			throw new \InvalidArgumentException( 'API key có ký tự không hợp lệ.' );
		}
		if ( ! $key && ! Settings::key( $provider ) ) {
			throw new \InvalidArgumentException( 'Nhập API key hoặc lưu API key trong API Providers trước.' );
		}
		$cat = absint( $d['category'] ?? 0 );
		$term = get_term( $cat, 'category' );
		if ( ! $term || is_wp_error( $term ) ) {
			throw new \InvalidArgumentException( 'Danh mục WordPress không hợp lệ.' );
		}
		$titles = preg_split( '/\r\n|\r|\n/', (string) ( $d['titles'] ?? '' ) );
		$rows = array();
		foreach ( $titles as $title ) {
			if ( trim( $title ) ) { $rows[] = array( 'title' => $title ); }
		}
		if ( ! empty( $_FILES['matrix_file']['name'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Importer validates uploaded temp file and content.
			$rows = array_merge( $rows, Importer::upload( $_FILES['matrix_file'] ) );
		}
		if ( count( $rows ) < $count ) {
			throw new \InvalidArgumentException( 'Bạn chọn ' . $count . ' bài nhưng chỉ nhập ' . count( $rows ) . ' tiêu đề. Hãy thêm tiêu đề hoặc giảm số bài.' );
		}
		$rows = array_slice( $rows, 0, $count );
		$config = Settings::campaign_config( array(
			'provider' => $provider,
			'model' => $model,
			'language' => sanitize_text_field( $d['language'] ?? 'vi' ),
			'category' => $cat,
			'min_words' => max( 1000, intdiv( $words * 90, 100 ) ),
			'max_words' => min( 5000, intdiv( $words * 110 + 99, 100 ) ),
			'intent' => 'Informational',
			'mode' => 'draft',
			'batch_size' => 20,
			'daily_articles' => $count,
			'qa_threshold' => 75,
			'max_repairs' => 2,
		) );
		$cfg = Settings::provider( $provider );
		if ( ! $cfg['model'] ) { $cfg['model'] = $model; }
		$tokens = absint( $d['output_tokens'] ?? 16000 );
		if ( ! in_array( $tokens, array( 4000, 8000, 16000, 32000, 64000 ), true ) ) {
			throw new \InvalidArgumentException( 'Giới hạn output tokens không hợp lệ.' );
		}
		$config['output_tokens'] = $tokens;
		// Save key only after every input and file has passed validation.
		if ( $key ) { update_option( 'masf_key_' . $provider, Settings::encrypt( $key ), false ); }
		update_option( 'masf_provider_' . $provider, $cfg, false );
		update_option( 'masf_last_quick_provider', $provider, false );
		update_option( 'masf_last_quick_model_' . $provider, $model, false );
		$name = 'Nhanh ' . wp_date( 'Y-m-d H:i', time() ) . ' — ' . $provider;
		$ok = $wpdb->insert( Store::table( 'campaigns' ), array(
			'name' => $name, 'status' => 'PAUSED', 'config' => wp_json_encode( $config ),
			'created_by' => get_current_user_id(), 'created_at' => Store::now(), 'updated_at' => Store::now(),
		) );
		if ( ! $ok ) { throw new \RuntimeException( 'Không tạo được chiến dịch.' ); }
		$id = (int) $wpdb->insert_id;
		$result = Importer::add( $id, $rows );
		if ( ! $result['added'] ) {
			// Preserve campaign for audit; nothing is billed and it stays PAUSED.
			throw new \InvalidArgumentException( 'Không có bài mới được thêm (có thể tất cả tiêu đề bị trùng). Chiến dịch đã lưu nhưng chưa chạy.' );
		}
		Queue::control( $id, 'start' );
		return array( 'id' => $id, 'message' => 'Đã tạo campaign #' . $id . ' và xếp ' . $result['added'] . '/' . $count . ' bài Draft vào hàng đợi. ' . ( $result['skipped'] ? 'Bỏ qua ' . count( $result['skipped'] ) . ' tiêu đề trùng/không hợp lệ. ' : '' ) . 'Không xác nhận số dư tiền API; xem lỗi ở dưới nếu nhà cung cấp từ chối request.' );
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
