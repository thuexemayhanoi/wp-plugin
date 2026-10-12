<?php
/** Official HTTPS provider adapter.
 *
 * @package MultiAISEOContentFactory
 */

namespace MASF;

defined( 'ABSPATH' ) || exit;

/** Adapter implementation for the factory workflow. */
abstract class Adapter {
	/**
	 * Key.
	 *
	 * @var mixed
	 */
	protected $key;
	/**
	 * Config.
	 *
	 * @var mixed
	 */
	protected $config;
	/**
	 * Initialize instance.
	 *
	 * @param mixed $key Key.
	 * @param array $config Config.
	 */
	public function __construct( $key, $config ) {
		$this->key    = $key;
		$this->config = $config; }
	/**
	 * Generate one text response with usage details.
	 *
	 * @param string $model Model ID.
	 * @param string $system Instructions.
	 * @param string $prompt User context.
	 * @param int    $tokens Output limit.
	 * @return array Response envelope.
	 */
	abstract public function generate( $model, $system, $prompt, $tokens );
	/**
	 * Fetch the provider model catalog.
	 *
	 * @return array Model IDs.
	 */
	abstract public function models();
	/**
	 * Classify provider errors using documented error codes instead of
	 * mistaking every 429 for low funds. Never expose a raw provider body.
	 *
	 * @param int $http Status code.
	 * @param string $body JSON body from provider.
	 * @param int $delay Retry after.
	 * @return API_Error Safely-worded and actionable error.
	 */
	public static function classify_error( $http, $body, $delay = 0 ) {
		$j = json_decode( $body, true );
		$err = is_array( $j['error'] ?? null ) ? $j['error'] : array();
		$code = strtolower( (string) ( $err['code'] ?? '' ) );
		$type = strtolower( (string) ( $err['type'] ?? ( $err['status'] ?? '' ) ) );
		$detail = strtolower( (string) ( $err['message'] ?? '' ) );
		$signature = substr( $code . ' ' . $type . ' ' . $detail, 0, 2000 );
		$billing = 402 === $http || (bool) preg_match( '/insufficient[_ -]?quota|billing[_ -]?error|payment[_ -]?required|credit[_ -]?balance[_ -]?exhausted|prepay(?:ment)? credits? (?:are )?depleted|quota exceeded.*(?:billing|budget|spend)|spend(?:ing)?[_ -]?limit|spend[_ -]?limit[_ -]?exceeded|usage[_ -]?limit[_ -]?exceeded|not enough credits|credit balance (?:too low|depleted)|out of credits|purchase credits|billing limit/i', $signature );
		if ( $billing ) {
			return new API_Error( 'API hết credit hoặc chạm giới hạn chi tiêu/thanh toán (HTTP ' . $http . '). Mở Billing/Usage của nhà cung cấp, nạp tiền hoặc nâng hạn mức rồi Retry Failed → Resume. Plugin đã tạm dừng chiến dịch.', $http, 0, false, true );
		}
		if ( 401 === $http ) {
			return new API_Error( 'API key không hợp lệ, hết hạn hoặc bị thu hồi (401). Kiểm tra lại key.', $http, 0, false, true );
		}
		if ( 403 === $http ) {
			return new API_Error( 'API không có quyền dùng model hoặc bị chặn (403). Kiểm tra quyền API.', $http, 0, false, true );
		}
		if ( 404 === $http ) {
			return new API_Error( 'Model ID hoặc endpoint không tồn tại (404). Kiểm tra lại Model ID.', $http, 0, false, true );
		}
		if ( 400 === $http || 422 === $http ) {
			return new API_Error( 'API từ chối tham số/model (HTTP ' . $http . '). Kiểm tra Model ID và giới hạn output token; model có thể không hỗ trợ JSON mode hoặc mức token đã chọn.', $http, 0, false, true );
		}
		if ( 429 === $http ) {
			return new API_Error( 'API báo 429: quá tốc độ hoặc vượt hạn mức request/token. Chưa thể kết luận đã hết tiền. Plugin sẽ thử lại có giãn cách; xem Billing/Usage nếu lỗi kéo dài.', $http, min( 86400, $delay ), true, false );
		}
		return new API_Error( 'Nhà cung cấp API trả HTTP ' . $http . '. Kiểm tra dịch vụ và logs.', $http, min( 86400, $delay ), $http >= 500 || 408 === $http, $http >= 400 && $http < 500 );
	}

	/**
	 * Request.
	 *
	 * @param mixed $url Url.
	 * @param mixed $body Body.
	 * @param array $headers Headers.
	 *
	 * @return mixed Operation result.
	 * @throws API_Error If the operation cannot complete.
	 */
	protected function request( $url, $body = null, $headers = array() ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || ! in_array( $host, array( 'api.anthropic.com', 'generativelanguage.googleapis.com', 'api.openai.com' ), true ) ) {
			throw new API_Error( 'Endpoint không được phép.' );
		}
		$args = array(
			'timeout'             => min( 60, max( 10, (int) $this->config['timeout'] ) ),
			'redirection'         => 0,
			'sslverify'           => true,
			'limit_response_size' => 2 * MB_IN_BYTES,
			'headers'             => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
		);
		if ( null === $body ) {
			$r = wp_remote_get( $url, $args ); } else {
			$args['body'] = wp_json_encode( $body );
			$r            = wp_remote_post( $url, $args ); }
			if ( is_wp_error( $r ) ) {
				throw new API_Error( 'Không nhận được phản hồi API; kiểm tra mạng/timeout. Chi phí được giữ dự phòng.', 0, 0, true ); }
			$http  = wp_remote_retrieve_response_code( $r );
			$retry = wp_remote_retrieve_header( $r, 'retry-after' );
			$delay = is_numeric( $retry ) ? (int) $retry : ( $retry ? max( 0, strtotime( $retry ) - time() ) : 0 );
			if ( $http < 200 || $http >= 300 ) {
				throw self::classify_error( (int) $http, wp_remote_retrieve_body( $r ), (int) $delay );
			}
			$json = json_decode( wp_remote_retrieve_body( $r ), true );
			if ( ! is_array( $json ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are plain text; Admin escapes HTML and REST serializes JSON at the output boundary.
				throw new API_Error( 'API trả JSON không hợp lệ.', $http, 0, true ); }
			// phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Intentional empty-value fallback; plugin requires PHP 8.1+.
			$json['_request_id'] = sanitize_text_field( wp_remote_retrieve_header( $r, 'request-id' ) ?: wp_remote_retrieve_header( $r, 'x-request-id' ) );
			// phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Intentional empty-value fallback; plugin requires PHP 8.1+.
			$json['_rate_remaining'] = sanitize_text_field( wp_remote_retrieve_header( $r, 'x-ratelimit-remaining-requests' ) ?: wp_remote_retrieve_header( $r, 'anthropic-ratelimit-requests-remaining' ) );
			return $json;
	}
}
