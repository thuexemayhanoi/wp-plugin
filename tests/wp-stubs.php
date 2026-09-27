<?php
/**
 * Minimal WordPress function stubs so the plugin's logic can be tested
 * outside a real WordPress install. Tests must not depend on a database.
 */

error_reporting( E_ALL );

$GLOBALS['__options']    = array();
$GLOBALS['__enqueued']  = array();
$GLOBALS['__actions']   = array();

// --- Options ---
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
}
function add_option( $name, $value ) {
	$GLOBALS['__options'][ $name ] = $value;
	return true;
}
function update_option( $name, $value ) {
	$GLOBALS['__options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['__options'][ $name ] );
	return true;
}

// --- Hooks ---
function add_action( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['__actions'][ $hook ][] = $cb;
	return true;
}
function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['__actions'][ $hook ] ?? array() as $cb ) {
		call_user_func_array( $cb, $args );
	}
}
function register_activation_hook( $file, $cb ) { return true; }

// --- i18n ---
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_attr_e( $s, $d = null ) { echo htmlspecialchars( $s, ENT_QUOTES ); }
function esc_html_e( $s, $d = null ) { echo htmlspecialchars( $s, ENT_QUOTES ); }
function load_plugin_textdomain( $d, $f = false, $p = '' ) { return true; }

// --- Escaping ---
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) {
	$s = (string) $s;
	if ( ! preg_match( '#^https?://#i', $s ) && ! preg_match( '#^(tel|mailto|sms):#i', $s ) ) {
		return '';
	}
	return htmlspecialchars( $s, ENT_QUOTES );
}

// --- Sanitizing ---
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function absint( $n ) { return abs( (int) $n ); }
function sanitize_email( $s ) {
	$s = trim( (string) $s );
	return filter_var( $s, FILTER_VALIDATE_EMAIL ) ? $s : '';
}
function is_email( $s ) { return (bool) filter_var( (string) $s, FILTER_VALIDATE_EMAIL ); }
function esc_url_raw( $s, $protocols = null ) {
	$s = trim( (string) $s );
	if ( ! preg_match( '#^https?://#i', $s ) ) {
		return '';
	}
	if ( is_array( $protocols ) && ! preg_match( '#^' . implode( '|', $protocols ) . '://#i', $s ) ) {
		return '';
	}
	return filter_var( $s, FILTER_SANITIZE_URL );
}
function sanitize_hex_color( $s ) {
	$s = trim( (string) $s );
	if ( '' === $s ) {
		return '';
	}
	if ( preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $s ) ) {
		return $s;
	}
	return null;
}

// --- Assets ---
function wp_register_style( $h, $src, $deps = array(), $ver = false ) { return true; }
function wp_register_script( $h, $src, $deps = array(), $ver = false, $footer = false ) { return true; }
function wp_enqueue_style( $h ) { $GLOBALS['__enqueued'][ $h ] = 'style'; return true; }
function wp_enqueue_script( $h ) { $GLOBALS['__enqueued'][ $h ] = 'script'; return true; }
function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/quickcall-connect/'; }
function plugin_basename( $f ) { return 'quickcall-connect/quickcall-connect.php'; }

// --- Admin ---
function is_admin() { return isset( $GLOBALS['__is_admin'] ) ? $GLOBALS['__is_admin'] : false; }
function add_options_page( ...$a ) { return 'settings_page_quickcall-connect'; }
function register_setting( ...$a ) { return true; }
function settings_fields( ...$a ) { return ''; }
function submit_button( ...$a ) { echo ''; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['__user_can'] ); }
function wp_die( $m ) { throw new Exception( (string) $m ); }
function checked( $a, $b = true ) { echo ( (string) $a === (string) $b ) ? 'checked="checked"' : ''; }
function selected( $a, $b ) { echo ( (string) $a === (string) $b ) ? 'selected="selected"' : ''; }

define( 'ABSPATH', '/tmp/fake-wp/' );
define( 'QUICKCALL_CONNECT_VERSION', '0.1.0' );
define( 'QUICKCALL_CONNECT_OPTION', 'quickcall_connect_settings' );
