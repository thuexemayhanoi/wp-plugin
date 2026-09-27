<?php
/**
 * QuickCall Connect settings: defaults, sanitization, access.
 *
 * @package QuickCallConnect
 */

namespace QuickCallConnect;

defined( 'ABSPATH' ) || exit;

/**
 * Settings class: defaults, sanitization and derived widget data.
 */
class Settings {

	const OPTION = 'quickcall_connect_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		$channels = array();
		foreach ( Registry::channels() as $key => $meta ) {
			$channels[ $key ] = array(
				'value' => '',
				'label' => '',
			);
		}

		return array(
			'enabled'    => 1,
			'placement'  => 'right',
			'channels'   => $channels,
			'custom'     => array(
				'label' => '',
				'url'   => '',
				'icon'  => 'chat',
			),
			'appearance' => array(
				'bg'          => '#1a9d61',
				'fg'          => '#ffffff',
				'accent'      => '',
				'size'        => 'md',
				'gap'         => 'md',
				'radius'      => 'md',
				'offset_x'    => 16,
				'offset_y'    => 16,
				'show_labels' => 1,
				'animation'   => 'subtle',
				'new_tab'     => 1,
			),
		);
	}

	/**
	 * Get merged settings.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return self::merge( self::defaults(), $saved );
	}

	/**
	 * Recursive defaults merge for known keys only.
	 *
	 * @param array $defaults Defaults.
	 * @param array $saved    Saved values.
	 * @return array
	 */
	private static function merge( $defaults, $saved ) {
		$out = array();
		foreach ( $defaults as $key => $default ) {
			if ( is_array( $default ) ) {
				$out[ $key ] = self::merge( $default, isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ? $saved[ $key ] : array() );
			} else {
				$out[ $key ] = array_key_exists( $key, $saved ) ? $saved[ $key ] : $default;
			}
		}
		return $out;
	}

	/**
	 * Sanitize callback for register_setting(). All input is untrusted.
	 *
	 * @param array $input Raw input.
	 * @return array Clean settings.
	 */
	public static function sanitize( $input ) {
		$clean = self::defaults();

		// General.
		$clean['enabled'] = empty( $input['enabled'] ) ? 0 : 1;

		$clean['placement'] = 'right';
		if ( isset( $input['placement'] ) && in_array( (string) $input['placement'], Registry::placements(), true ) ) {
			$clean['placement'] = (string) $input['placement'];
		}

		// Channels.
		$raw_channels = array();
		if ( isset( $input['channels'] ) && is_array( $input['channels'] ) ) {
			$raw_channels = $input['channels'];
		}
		foreach ( Registry::channels() as $key => $meta ) {
			$clean['channels'][ $key ] = array(
				'value' => '',
				'label' => '',
			);
			if ( isset( $raw_channels[ $key ] ) && is_array( $raw_channels[ $key ] ) ) {
				$raw                                = $raw_channels[ $key ];
				$clean['channels'][ $key ]['value'] = self::sanitize_value( $meta['sanitize'], isset( $raw['value'] ) ? $raw['value'] : '' );
				// Labels are plain text only.
				$clean['channels'][ $key ]['label'] = sanitize_text_field( isset( $raw['label'] ) ? $raw['label'] : '' );
			}
		}

		// Custom link / chatbot.
		$custom                   = isset( $input['custom'] ) && is_array( $input['custom'] ) ? $input['custom'] : array();
		$clean['custom']['label'] = sanitize_text_field( isset( $custom['label'] ) ? $custom['label'] : '' );
		$clean['custom']['url']   = Registry::safe_url( isset( $custom['url'] ) ? $custom['url'] : '' );
		$icon                     = isset( $custom['icon'] ) ? (string) $custom['icon'] : 'chat';
		if ( ! array_key_exists( $icon, Registry::custom_icons() ) ) {
			$icon = 'chat';
		}
		$clean['custom']['icon'] = $icon;

		// Appearance.
		$app = isset( $input['appearance'] ) && is_array( $input['appearance'] ) ? $input['appearance'] : array();

		$clean['appearance']['bg']     = self::sanitize_color( isset( $app['bg'] ) ? $app['bg'] : '' );
		$clean['appearance']['fg']     = self::sanitize_color( isset( $app['fg'] ) ? $app['fg'] : '' );
		$clean['appearance']['accent'] = self::sanitize_color( isset( $app['accent'] ) ? $app['accent'] : '' );

		$clean['appearance']['size']   = self::enum( isset( $app['size'] ) ? $app['size'] : '', array( 'sm', 'md', 'lg' ), 'md' );
		$clean['appearance']['gap']    = self::enum( isset( $app['gap'] ) ? $app['gap'] : '', array( 'sm', 'md', 'lg' ), 'md' );
		$clean['appearance']['radius'] = self::enum( isset( $app['radius'] ) ? $app['radius'] : '', array( 'sm', 'md', 'pill' ), 'md' );

		$clean['appearance']['offset_x'] = self::clamp_int( isset( $app['offset_x'] ) ? $app['offset_x'] : 16, 0, 150, 16 );
		$clean['appearance']['offset_y'] = self::clamp_int( isset( $app['offset_y'] ) ? $app['offset_y'] : 16, 0, 150, 16 );

		$clean['appearance']['show_labels'] = empty( $app['show_labels'] ) ? 0 : 1;
		$clean['appearance']['animation']   = self::enum( isset( $app['animation'] ) ? $app['animation'] : '', array( 'none', 'subtle' ), 'subtle' );
		$clean['appearance']['new_tab']     = empty( $app['new_tab'] ) ? 0 : 1;

		return $clean;
	}

	/**
	 * Sanitize a channel value by type.
	 *
	 * @param string $type  'phone'|'url'|'email'.
	 * @param mixed  $value Raw value.
	 * @return string
	 */
	public static function sanitize_value( $type, $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		switch ( $type ) {
			case 'phone':
				$phone = preg_replace( '/[^0-9+]/', '', $value );
				if ( ! preg_match( '/^\+?[0-9]{6,20}$/', $phone ) ) {
					return '';
				}
				return $phone;
			case 'email':
				$email = sanitize_email( $value );
				return is_email( $email ) ? $email : '';
			case 'url':
				return Registry::safe_url( $value );
		}
		return '';
	}

	/**
	 * Sanitize a hex color. Empty string allowed (unset).
	 *
	 * @param string $color Raw.
	 * @return string
	 */
	public static function sanitize_color( $color ) {
		$color = sanitize_hex_color( (string) $color );
		return ( null === $color ) ? '' : $color;
	}

	/**
	 * Strict enum check with fallback.
	 *
	 * @param string   $value     Raw.
	 * @param string[] $allowed   Allowlist.
	 * @param string   $fallback  Default.
	 * @return string
	 */
	public static function enum( $value, $allowed, $fallback ) {
		$value = (string) $value;
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Cast to int and clamp.
	 *
	 * @param mixed $raw      Raw.
	 * @param int   $min      Min.
	 * @param int   $max      Max.
	 * @param int   $fallback Default when not numeric.
	 * @return int
	 */
	public static function clamp_int( $raw, $min, $max, $fallback ) {
		if ( is_numeric( $raw ) ) {
			return max( $min, min( $max, (int) $raw ) );
		}
		return $fallback;
	}

	/**
	 * Active channels: every configured channel that resolves to a valid href,
	 * in registry order, plus the custom link at the end.
	 *
	 * @param array $settings Merged settings.
	 * @return array[] List of { key, label, href, icon, external }.
	 */
	public static function active_channels( $settings ) {
		$active = array();
		if ( empty( $settings['enabled'] ) ) {
			return $active;
		}

		foreach ( Registry::channels() as $key => $meta ) {
			$value = isset( $settings['channels'][ $key ]['value'] ) ? $settings['channels'][ $key ]['value'] : '';
			$href  = Registry::href( $key, $value );
			if ( '' === $href ) {
				continue; // Blank/invalid channels stay hidden.
			}
			$label    = $settings['channels'][ $key ]['label'];
			$active[] = array(
				'key'      => $key,
				'label'    => '' !== $label ? $label : $meta['label'],
				'href'     => $href,
				'icon'     => $meta['icon'],
				'external' => ( 'url' === $meta['kind'] ),
			);
		}

		$custom_url = isset( $settings['custom']['url'] ) ? $settings['custom']['url'] : '';
		$custom_url = Registry::safe_url( $custom_url );
		if ( '' !== $custom_url ) {
			$custom_label = isset( $settings['custom']['label'] ) ? $settings['custom']['label'] : '';
			$custom_icon  = isset( $settings['custom']['icon'] ) ? $settings['custom']['icon'] : 'chat';
			if ( ! array_key_exists( $custom_icon, Registry::custom_icons() ) ) {
				$custom_icon = 'chat';
			}
			$active[] = array(
				'key'      => 'custom',
				'label'    => '' !== $custom_label ? $custom_label : __( 'Chat with us', 'quickcall-connect' ),
				'href'     => $custom_url,
				'icon'     => $custom_icon,
				'external' => true,
			);
		}

		return $active;
	}

	/**
	 * CSS custom properties for the widget root.
	 *
	 * @param array $settings Merged settings.
	 * @return string Inline style attribute value (already escaped parts, built from validated data).
	 */
	public static function css_vars( $settings ) {
		$app = $settings['appearance'];

		$sizes = array(
			'sm' => '46px',
			'md' => '54px',
			'lg' => '62px',
		);
		$gaps  = array(
			'sm' => '6px',
			'md' => '10px',
			'lg' => '16px',
		);
		$radii = array(
			'sm'   => '12px',
			'md'   => '18px',
			'pill' => '999px',
		);

		$vars   = array();
		$vars[] = '--qc-bg:' . ( '' !== $app['bg'] ? $app['bg'] : '#1a9d61' );
		$vars[] = '--qc-fg:' . ( '' !== $app['fg'] ? $app['fg'] : '#ffffff' );
		$vars[] = '--qc-accent:' . ( '' !== $app['accent'] ? $app['accent'] : $app['bg'] );
		$vars[] = '--qc-size:' . ( isset( $sizes[ $app['size'] ] ) ? $sizes[ $app['size'] ] : '54px' );
		$vars[] = '--qc-gap:' . ( isset( $gaps[ $app['gap'] ] ) ? $gaps[ $app['gap'] ] : '10px' );
		$vars[] = '--qc-radius:' . ( isset( $radii[ $app['radius'] ] ) ? $radii[ $app['radius'] ] : '18px' );
		$vars[] = '--qc-ox:' . (int) $app['offset_x'] . 'px';
		$vars[] = '--qc-oy:' . (int) $app['offset_y'] . 'px';

		return implode( ';', $vars );
	}
}
