<?php
/** Validated settings; encrypted secrets never reach admin HTML or REST payloads. *
 *
 * @package MultiAISEOContentFactory
 */

namespace MASF;

defined( 'ABSPATH' ) || exit;

/** Settings implementation for the factory workflow. */
final class Settings {
	/**
	 * Global.
	 *
	 * @return mixed Operation result.
	 */
	public static function global() {
		return wp_parse_args(
			get_option( 'masf_settings', array() ),
			array(
				'concurrency' => 1,
				'max_retries' => 4,
				'log_days'    => 30,
				'title_min'   => 50,
				'title_max'   => 75,
				'meta_min'    => 120,
				'meta_max'    => 180,
			)
		);
	}
	/**
	 * Provider.
	 *
	 * @param string $name Name.
	 *
	 * @return mixed Operation result.
	 */
	public static function provider( $name ) {
		return wp_parse_args(
			get_option( 'masf_provider_' . $name, array() ),
			array(
				'model'         => '',
				'output_tokens' => 16000,
				'timeout'       => 45,
				'rpm'           => 6,
				'daily_tokens'  => 6000000,
				'input_price'   => 0,
				'output_price'  => 0,
			)
		);
	}
	/**
	 * Brand.
	 *
	 * @return mixed Operation result.
	 */
	public static function brand() {
		return get_option( 'masf_brand', array() ); }
	/**
	 * Encrypt.
	 *
	 * @param string $plain Plain.
	 *
	 * @return mixed Operation result.
	 * @throws \RuntimeException If the operation cannot complete.
	 */
	public static function encrypt( $plain ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			throw new \RuntimeException( 'Máy chủ cần OpenSSL để lưu khóa.' ); }
		$key    = hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher ) {
			throw new \RuntimeException( 'Không mã hóa được khóa.' ); }
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding an encrypted AES-GCM blob for safe option storage, no code obfuscation.
		return base64_encode( $iv . $tag . $cipher );
	}
	/**
	 * Key.
	 *
	 * @param string $name Name.
	 *
	 * @return mixed Operation result.
	 */
	public static function key( $name ) {
		$env = array(
			'claude' => 'MASF_CLAUDE_API_KEY',
			'gemini' => 'MASF_GEMINI_API_KEY',
			'openai' => 'MASF_OPENAI_API_KEY',
		);
		if ( isset( $env[ $name ] ) && defined( $env[ $name ] ) ) {
			return (string) constant( $env[ $name ] ); }
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a stored ciphertext blob only, never executing it.
		$data = base64_decode( get_option( 'masf_key_' . $name, '' ), true );
		if ( ! $data || strlen( $data ) < 29 ) {
			return ''; }
		$key = hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
		// phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Intentional empty-value fallback; plugin requires PHP 8.1+.
		return openssl_decrypt( substr( $data, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $data, 0, 12 ), substr( $data, 12, 16 ) ) ?: '';
	}
	/**
	 * Model.
	 *
	 * @param mixed $value Value.
	 *
	 * @return mixed Operation result.
	 * @throws \InvalidArgumentException If the operation cannot complete.
	 */
	public static function model( $value ) {
		$value = trim( (string) $value );
		if ( $value && ! preg_match( '/^[a-zA-Z0-9._:\/-]{1,150}$/D', $value ) ) {
			throw new \InvalidArgumentException( 'Model ID không hợp lệ.' ); }
		return $value;
	}
	/**
	 * Campaign config.
	 *
	 * @param array $d D.
	 *
	 * @return mixed Operation result.
	 * @throws \InvalidArgumentException If the operation cannot complete.
	 */
	public static function campaign_config( $d ) {
		$provider  = in_array( $d['provider'] ?? '', array( 'claude', 'gemini', 'openai' ), true ) ? $d['provider'] : 'claude';
		$providers = array( '', 'claude', 'gemini', 'openai' );
		$min       = max( 1500, min( 10000, absint( $d['min_words'] ?? 1500 ) ) );
		$max       = max( $min, min( 10000, absint( $d['max_words'] ?? 2000 ) ) );
		$mode      = in_array( $d['mode'] ?? '', array( 'draft', 'pending', 'publish', 'schedule' ), true ) ? $d['mode'] : 'draft';
		$start     = sanitize_text_field( $d['schedule_start'] ?? '' );
		if ( 'schedule' === $mode ) {
			$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $start, wp_timezone() );
			if ( ! $dt || $dt->format( 'Y-m-d\TH:i' ) !== $start || $dt->getTimestamp() <= time() ) {
				throw new \InvalidArgumentException( 'Chọn ngày giờ tương lai theo múi giờ WordPress.' ); }
		}
		return array(
			'simple_mode'       => ! empty( $d['simple_mode'] ),
			'simple_headings'   => array( 'h2' => max( 1, min( 20, absint( $d['h2'] ?? 5 ) ) ), 'h3' => min( 30, absint( $d['h3'] ?? 6 ) ), 'h4' => min( 30, absint( $d['h4'] ?? 0 ) ), 'h5' => min( 30, absint( $d['h5'] ?? 0 ) ), 'h6' => min( 30, absint( $d['h6'] ?? 0 ) ) ),
			'language'          => preg_match( '/^[a-z]{2}(?:[-_][A-Za-z]{2})?$/D', $d['language'] ?? '' ) ? $d['language'] : 'vi',
			'provider'          => $provider,
			'model'             => self::model( $d['model'] ?? '' ),
			'fallback'          => in_array( $d['fallback'] ?? '', $providers, true ) ? ( $d['fallback'] ?? '' ) : '',
			'fallback_model'    => self::model( $d['fallback_model'] ?? '' ),
			'reviewer'          => in_array( $d['reviewer'] ?? '', $providers, true ) ? ( $d['reviewer'] ?? '' ) : '',
			'reviewer_model'    => self::model( $d['reviewer_model'] ?? '' ),
			'repair_provider'   => in_array( $d['repair_provider'] ?? '', $providers, true ) ? ( $d['repair_provider'] ?? '' ) : '',
			'repair_model'      => self::model( $d['repair_model'] ?? '' ),
			'min_words'         => $min,
			'max_words'         => $max,
			'intent'            => sanitize_text_field( $d['intent'] ?? 'Informational' ),
			'brief'             => mb_substr( sanitize_textarea_field( $d['brief'] ?? '' ), 0, 12000 ),
			'sources'           => mb_substr( sanitize_textarea_field( $d['sources'] ?? '' ), 0, 24000 ),
			'category'          => absint( $d['category'] ?? get_option( 'default_category' ) ),
			'internal_links'    => array_values( array_unique( array_filter( array_map( 'absint', preg_split( '/[\s,;]+/', (string) ( $d['internal_links'] ?? '' ) ) ) ) ) ),
			'mode'              => $mode,
			'auto_publish'      => ! empty( $d['auto_publish'] ) && in_array( $mode, array( 'publish', 'schedule' ), true ),
			'batch_size'        => in_array( absint( $d['batch_size'] ?? 5 ), array( 1, 5, 10, 20 ), true ) ? absint( $d['batch_size'] ?? 5 ) : 5,
			'daily_articles'    => max( 1, min( 500, absint( $d['daily_articles'] ?? 5 ) ) ),
			'concurrency'       => max( 1, min( 3, absint( $d['concurrency'] ?? 1 ) ) ),
			'budget'            => max( 0, (float) ( $d['budget'] ?? 0 ) ),
			'qa_threshold'      => max( 50, min( 100, absint( $d['qa_threshold'] ?? 75 ) ) ),
			'max_repairs'       => max( 0, min( 5, absint( $d['max_repairs'] ?? 2 ) ) ),
			'require_review'    => ! empty( $d['require_review'] ),
			'schedule_start'    => $start,
			'schedule_interval' => max( 1, absint( $d['schedule_interval'] ?? 24 ) ),
		);
	}
}
