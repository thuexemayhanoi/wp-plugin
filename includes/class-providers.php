<?php
/** Official HTTPS provider adapter.
 *
 * @package MultiAISEOContentFactory
 */

namespace MASF;

defined( 'ABSPATH' ) || exit;

foreach ( array( 'api-error', 'adapter', 'claude', 'gemini', 'openai' ) as $masf_adapter ) {
	require_once MASF_DIR . 'includes/class-' . $masf_adapter . '.php';
}

/** Providers implementation for the factory workflow. */
final class Providers {
	/**
	 * Adapter.
	 *
	 * @param string $name Name.
	 *
	 * @return mixed Operation result.
	 * @throws API_Error If the operation cannot complete.
	 */
	public static function adapter( $name ) {
		$classes = array(
			'claude' => Claude::class,
			'gemini' => Gemini::class,
			'openai' => OpenAI::class,
		);
		if ( ! isset( $classes[ $name ] ) ) {
			throw new API_Error( 'Nhà cung cấp không hợp lệ.' ); }
		$key = Settings::key( $name );
		if ( ! $key ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are plain text; Admin escapes HTML and REST serializes JSON at the output boundary.
			throw new API_Error( 'Chưa có API key cho ' . $name . '.' ); }
		return new $classes[ $name ]( $key, Settings::provider( $name ) );
	}
	/**
	 * Models.
	 *
	 * @param string $name Name.
	 *
	 * @return mixed Operation result.
	 */
	public static function models( $name ) {
		$models = self::adapter( $name )->models();
		$models = array_values(
			array_unique(
				array_filter(
					$models,
					function ( $m ) {
						return is_string( $m ) && preg_match( '/^[a-zA-Z0-9._:\/-]{1,150}$/D', $m );
					}
				)
			)
		);
		sort( $models );
		set_transient( 'masf_models_' . $name, $models, DAY_IN_SECONDS );
		return $models;
	}
	/**
	 * Call.
	 *
	 * @param string $name Name.
	 * @param string $model Model.
	 * @param string $system System.
	 * @param string $prompt Prompt.
	 * @param mixed  $campaign Campaign.
	 * @param int    $article Article.
	 * @param int    $max_tokens Max tokens.
	 *
	 * @return mixed Operation result.
	 * @throws API_Error If the operation cannot complete.
	 */
	public static function call( $name, $model, $system, $prompt, $campaign = 0, $article = 0, $max_tokens = null ) {
		global $wpdb;
		$config = Settings::provider( $name );
		// phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Intentional empty-value fallback; plugin requires PHP 8.1+.
		$model = Settings::model( $model ?: $config['model'] );
		if ( ! $model ) {
			throw new API_Error( 'Cần chọn Model ID trong API Providers hoặc chiến dịch.', 0, 0, false, true ); }
		$adapter = self::adapter( $name );
		$limit   = (int) $config['output_tokens'];
		if ( $campaign ) {
			$campaign_data = Store::campaign( $campaign );
			if ( isset( $campaign_data['config']['output_tokens'] ) ) {
				$limit = max( 512, min( 64000, (int) $campaign_data['config']['output_tokens'] ) );
			}
		}
		$tokens  = min( $limit, $max_tokens ?? $limit );
		// UTF-8 byte length is a conservative input token reservation, not a tokenizer.
		$reserved = strlen( $system . $prompt ) + $tokens;
		$cost     = strlen( $system . $prompt ) * (float) $config['input_price'] / 1000000 + $tokens * (float) $config['output_price'] / 1000000;
		$lock     = Store::lease( 'api-quota', 15 );
		if ( ! $lock ) {
			throw new API_Error( 'Hạn mức đang được kiểm tra; chờ worker khác.', 429, 15, true ); }
		$t = Store::table( 'requests' );
		try {
			$minute = gmdate( 'Y-m-d H:i:s', time() - 60 );
			$day    = gmdate( 'Y-m-d 00:00:00' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Table identifiers come only from Store::table() or wpdb; values use placeholders.
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE provider=%s AND created_at>=%s", $name, $minute ) );
			if ( $count >= $config['rpm'] ) {
				throw new API_Error( 'Đã chạm giới hạn request/phút cục bộ.', 429, 60, true ); }
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Table identifiers come only from Store::table() or wpdb; values use placeholders.
			$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(IF(state IN ('reserved','unknown'),reserved_tokens,input_tokens+output_tokens)),0) FROM $t WHERE provider=%s AND created_at>=%s", $name, $day ) );
			if ( $used + $reserved > $config['daily_tokens'] ) {
				throw new API_Error( 'Chạm giới hạn token/ngày của plugin. Tăng hạn mức trong API Providers hoặc tiếp tục ngày sau; chiến dịch đã tạm dừng.', 0, 0, false, true ); }
			if ( $campaign ) {
				$c      = Store::campaign( $campaign );
				$budget = (float) ( $c['config']['budget'] ?? 0 );
				if ( $budget > 0 ) {
					if ( $config['input_price'] <= 0 || $config['output_price'] <= 0 ) {
						throw new API_Error( 'Nhập giá input/output của model để áp dụng ngân sách.', 0, 0, false, true ); }
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Table identifiers come only from Store::table() or wpdb; values use placeholders.
					$spent = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(IF(state IN ('reserved','unknown'),reserved_cost,cost)),0) FROM $t WHERE campaign_id=%d", $campaign ) );
					if ( $spent + $cost > $budget ) {
						throw new API_Error( 'Ngân sách chiến dịch không đủ cho request kế tiếp; tăng ngân sách hoặc giảm độ dài.', 0, 0, false, true ); }
				}
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Factory checkpoints require direct custom-table operations.
			$inserted = $wpdb->insert(
				$t,
				array(
					'campaign_id'     => $campaign,
					'article_id'      => $article,
					'provider'        => $name,
					'model'           => $model,
					'reserved_tokens' => $reserved,
					'reserved_cost'   => $cost,
					'state'           => 'reserved',
					'created_at'      => Store::now(),
				)
			);
			if ( ! $inserted ) {
				throw new API_Error( 'Không lưu được dự phòng API; request chưa gửi.' ); }
			$id = $wpdb->insert_id;
		} finally {
			Store::release( 'api-quota', $lock ); }
		try {
			$result = $adapter->generate( $model, $system, $prompt, $tokens );
			$actual = $result['input'] * (float) $config['input_price'] / 1000000 + $result['output'] * (float) $config['output_price'] / 1000000;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache.
			$wpdb->update(
				$t,
				array(
					'state'         => 'done',
					'cost'          => $actual,
					'input_tokens'  => $result['input'],
					'output_tokens' => $result['output'],
					'request_id'    => mb_substr( $result['request_id'], 0, 200 ),
					'http_status'   => 200,
				),
				array( 'id' => $id )
			);
			if ( $result['incomplete'] || ! trim( $result['text'] ) ) {
				throw new API_Error( 'API dừng trước khi hoàn thành (token limit, refusal hoặc safety). Tăng output token/giảm độ dài hoặc đổi model.' ); }
			$result['provider'] = $name;
			$result['model']    = $model;
			return $result;
		} catch ( API_Error $e ) {
			// Preserve actual usage recorded above; timeouts remain conservatively reserved.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Table identifiers come only from Store::table() or wpdb; values use placeholders.
			$state = $wpdb->get_var( $wpdb->prepare( "SELECT state FROM $t WHERE id=%d", $id ) );
			if ( 'reserved' === $state ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache.
				$wpdb->update(
					$t,
					array(
						'state'       => $e->http >= 400 && $e->http < 500 ? 'error' : 'unknown',
						'http_status' => $e->http,
					),
					array( 'id' => $id )
				); }
			throw $e;
		}
	}
	/**
	 * Json.
	 *
	 * @param string $text Text.
	 *
	 * @return mixed Operation result.
	 * @throws API_Error If the operation cannot complete.
	 */
	public static function json( $text ) {
		$text = preg_replace( '/^\s*```(?:json)?\s*|\s*```\s*$/i', '', trim( $text ) );
		$data = json_decode( $text, true, 32 );
		if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
			throw new API_Error( 'Nội dung AI không đúng JSON; đưa về REPAIR.' ); }
		return $data;
	}
}
