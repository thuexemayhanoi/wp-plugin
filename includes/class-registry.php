<?php
/**
 * QuickCall Connect channel registry — single source of truth.
 *
 * Every channel is defined here once. Rendering, sanitizing, and the admin UI
 * all read from this registry, so there are no duplicated conditionals.
 *
 * @package QuickCallConnect
 */

namespace QuickCallConnect;

defined( 'ABSPATH' ) || exit;

/**
 * Channel registry class.
 */
class Registry {

	/**
	 * Get the full channel registry.
	 *
	 * Kind is how the link is built, sanitize is how the value is validated,
	 * group is 'primary' (Call/Zalo/Map) or 'optional' (hidden until filled),
	 * and icon is the key of an original bundled SVG glyph.
	 *
	 * @return array<string, array<string,string>>
	 */
	public static function channels() {
		return array(
			'call'        => array(
				'label'    => __( 'Call', 'quickcall-connect' ),
				'kind'     => 'tel',
				'sanitize' => 'phone',
				'group'    => 'primary',
				'icon'     => 'phone',
				'hint'     => __( 'Phone number, e.g. +84901234567', 'quickcall-connect' ),
			),
			'zalo'        => array(
				'label'    => __( 'Zalo', 'quickcall-connect' ),
				'kind'     => 'url',
				'sanitize' => 'url',
				'group'    => 'primary',
				'icon'     => 'chat',
				'hint'     => __( 'Full Zalo profile link, e.g. https://zalo.me/0901234567', 'quickcall-connect' ),
			),
			'map'         => array(
				'label'    => __( 'Map / Directions', 'quickcall-connect' ),
				'kind'     => 'url',
				'sanitize' => 'url',
				'group'    => 'primary',
				'icon'     => 'pin',
				'hint'     => __( 'Full map link, e.g. a Google Maps place URL', 'quickcall-connect' ),
			),
			'facebook'    => array(
				'label'    => __( 'Facebook Page', 'quickcall-connect' ),
				'kind'     => 'url',
				'sanitize' => 'url',
				'group'    => 'optional',
				'icon'     => 'facebook',
				'hint'     => __( 'Page URL, e.g. https://facebook.com/yourpage', 'quickcall-connect' ),
			),
			'messenger'   => array(
				'label'    => __( 'Facebook Messenger', 'quickcall-connect' ),
				'kind'     => 'url',
				'sanitize' => 'url',
				'group'    => 'optional',
				'icon'     => 'messenger',
				'hint'     => __( 'm.me link, e.g. https://m.me/yourpage', 'quickcall-connect' ),
			),
			'whatsapp'    => array(
				'label'    => __( 'WhatsApp', 'quickcall-connect' ),
				'kind'     => 'url',
				'sanitize' => 'url',
				'group'    => 'optional',
				'icon'     => 'whatsapp',
				'hint'     => __( 'wa.me link, e.g. https://wa.me/84901234567', 'quickcall-connect' ),
			),
			'tripadvisor' => array(
				'label'    => __( 'TripAdvisor', 'quickcall-connect' ),
				'kind'     => 'url',
				'sanitize' => 'url',
				'group'    => 'optional',
				'icon'     => 'tripadvisor',
				'hint'     => __( 'Listing URL', 'quickcall-connect' ),
			),
			'youtube'     => array(
				'label'    => __( 'YouTube', 'quickcall-connect' ),
				'kind'     => 'url',
				'sanitize' => 'url',
				'group'    => 'optional',
				'icon'     => 'youtube',
				'hint'     => __( 'Channel or video URL', 'quickcall-connect' ),
			),
			'tiktok'      => array(
				'label'    => __( 'TikTok', 'quickcall-connect' ),
				'kind'     => 'url',
				'sanitize' => 'url',
				'group'    => 'optional',
				'icon'     => 'tiktok',
				'hint'     => __( 'Profile URL', 'quickcall-connect' ),
			),
			'instagram'   => array(
				'label'    => __( 'Instagram', 'quickcall-connect' ),
				'kind'     => 'url',
				'sanitize' => 'url',
				'group'    => 'optional',
				'icon'     => 'instagram',
				'hint'     => __( 'Profile URL', 'quickcall-connect' ),
			),
			'x'           => array(
				'label'    => __( 'X / X.com', 'quickcall-connect' ),
				'kind'     => 'url',
				'sanitize' => 'url',
				'group'    => 'optional',
				'icon'     => 'x',
				'hint'     => __( 'Profile URL', 'quickcall-connect' ),
			),
			'pinterest'   => array(
				'label'    => __( 'Pinterest', 'quickcall-connect' ),
				'kind'     => 'url',
				'sanitize' => 'url',
				'group'    => 'optional',
				'icon'     => 'pinterest',
				'hint'     => __( 'Profile or board URL', 'quickcall-connect' ),
			),
			'reddit'      => array(
				'label'    => __( 'Reddit', 'quickcall-connect' ),
				'kind'     => 'url',
				'sanitize' => 'url',
				'group'    => 'optional',
				'icon'     => 'reddit',
				'hint'     => __( 'Profile or subreddit URL', 'quickcall-connect' ),
			),
			'email'       => array(
				'label'    => __( 'Email', 'quickcall-connect' ),
				'kind'     => 'mailto',
				'sanitize' => 'email',
				'group'    => 'optional',
				'icon'     => 'email',
				'hint'     => __( 'Email address', 'quickcall-connect' ),
			),
			'sms'         => array(
				'label'    => __( 'SMS', 'quickcall-connect' ),
				'kind'     => 'sms',
				'sanitize' => 'phone',
				'group'    => 'optional',
				'icon'     => 'sms',
				'hint'     => __( 'Phone number for text messages', 'quickcall-connect' ),
			),
			'telegram'    => array(
				'label'    => __( 'Telegram', 'quickcall-connect' ),
				'kind'     => 'url',
				'sanitize' => 'url',
				'group'    => 'optional',
				'icon'     => 'telegram',
				'hint'     => __( 't.me link, e.g. https://t.me/yourname', 'quickcall-connect' ),
			),
		);
	}

	/**
	 * Custom link icon allowlist. Icons are original bundled glyphs —
	 * no brand logos are copied.
	 *
	 * @return array<string, string>
	 */
	public static function custom_icons() {
		return array(
			'chat'  => __( 'Chat', 'quickcall-connect' ),
			'robot' => __( 'Robot', 'quickcall-connect' ),
			'link'  => __( 'Link', 'quickcall-connect' ),
		);
	}

	/**
	 * Return valid placement modes.
	 *
	 * @return string[]
	 */
	public static function placements() {
		return array( 'right', 'left', 'bottom' );
	}

	/**
	 * Original, locally bundled SVG glyphs. All icons are drawn as simple
	 * stroke primitives; no third-party brand logos are included.
	 *
	 * @param string $key Icon key.
	 * @return string Inline SVG markup, escaped.
	 */
	public static function icon( $key ) {
		$paths = array(
			'phone'       => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
			'pin'         => '<path d="M12 21s-7-5.5-7-11a7 7 0 0 1 14 0c0 5.5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
			'chat'        => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
			'messenger'   => '<path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5c-1.5 0-3-.4-4.2-1L3 20l1-5.3A8.5 8.5 0 1 1 21 11.5z"/><path d="M8.5 12.5c1.5-3 5.5-3 7 0"/>',
			'whatsapp'    => '<circle cx="12" cy="12" r="9"/><path d="M9.5 7.8l1.6.3.5 1.9-1 1a6.4 6.4 0 0 0 2.9 2.9l1-1 1.9.5.3 1.6a1.5 1.5 0 0 1-1.7 1.6A9.1 9.1 0 0 1 7.9 9.5a1.5 1.5 0 0 1 1.6-1.7z"/>',
			'tripadvisor' => '<circle cx="8.5" cy="13.5" r="3"/><circle cx="15.5" cy="13.5" r="3"/><path d="M3.5 13.5A8.5 8.5 0 0 1 12 5a8.5 8.5 0 0 1 8.5 8.5"/>',
			'youtube'     => '<rect x="3" y="6" width="18" height="12" rx="4"/><path d="M10.5 9.5l5 2.5-5 2.5z"/>',
			'tiktok'      => '<path d="M14 4v9.5a4 4 0 1 1-4-4"/><path d="M14 5a5 5 0 0 0 5 4"/>',
			'instagram'   => '<rect x="4" y="4" width="16" height="16" rx="5"/><circle cx="12" cy="12" r="3.5"/><path d="M16.8 7.2h.01"/>',
			'facebook'    => '<circle cx="12" cy="12" r="9"/><path d="M14.5 8h-1a2.5 2.5 0 0 0-2.5 2.5V19"/><path d="M9 13h5.5"/>',
			'x'           => '<path d="M5 5l14 14M19 5L5 19"/>',
			'pinterest'   => '<path d="M7 3h10v18l-5-4-5 4z"/>',
			'reddit'      => '<circle cx="12" cy="13.5" r="6.5"/><path d="M12 7V3.5"/><circle cx="12" cy="3" r="1"/><path d="M9 13h.01M15 13h.01"/>',
			'email'       => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
			'sms'         => '<path d="M21 13a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2z"/><path d="M8 9.5h.01M12 9.5h.01M16 9.5h.01"/>',
			'telegram'    => '<path d="M22 3L11 13"/><path d="M22 3l-7 19-4-9-9-4z"/>',
			'robot'       => '<rect x="5" y="8" width="14" height="10" rx="2"/><path d="M12 8V5"/><circle cx="12" cy="4" r="1"/><path d="M9 12h.01M15 12h.01"/><path d="M9.5 15.5h5"/>',
			'link'        => '<path d="M10 14a4 4 0 0 1 0-5.5l2-2a4 4 0 0 1 5.5 5.5l-1 1"/><path d="M14 10a4 4 0 0 1 0 5.5l-2 2a4 4 0 0 1-5.5-5.5l1-1"/>',
			'contact'     => '<path d="M4 5h16v11H8l-4 4z"/><path d="M8 9h8M8 12h5"/>',
		);

		if ( ! isset( $paths[ $key ] ) ) {
			$key = 'link';
		}

		return '<svg class="qc-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $key ] . '</svg>';
	}

	/**
	 * Build the href for a channel from its stored value.
	 *
	 * @param string $key    Channel key.
	 * @param string $value  Sanitized value.
	 * @return string href or '' if the value is invalid/empty.
	 */
	public static function href( $key, $value ) {
		$channels = self::channels();
		if ( ! isset( $channels[ $key ] ) || '' === $value ) {
			return '';
		}
		$kind = $channels[ $key ]['kind'];
		switch ( $kind ) {
			case 'tel':
				return 'tel:' . $value;
			case 'sms':
				return 'sms:' . $value;
			case 'mailto':
				return 'mailto:' . $value;
			case 'url':
			default:
				return self::safe_url( $value );
		}
	}

	/**
	 * Validate that a URL is http(s) only. Anything else (javascript:, data:, etc.) is rejected.
	 *
	 * @param string $url Raw URL.
	 * @return string Clean URL or ''.
	 */
	public static function safe_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		$clean = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' === $clean || ! preg_match( '#^https?://#i', $clean ) ) {
			return '';
		}
		return $clean;
	}
}
