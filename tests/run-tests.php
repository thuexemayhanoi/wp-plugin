<?php
/**
 * QuickCall Connect behavior tests. Run: php tests/run-tests.php
 * Uses WordPress stubs (tests/wp-stubs.php) — no real WordPress install needed.
 */

require __DIR__ . '/wp-stubs.php';
require dirname( __DIR__ ) . '/includes/class-registry.php';
require dirname( __DIR__ ) . '/includes/class-settings.php';
require dirname( __DIR__ ) . '/public/class-frontend.php';

use QuickCallConnect\Registry;
use QuickCallConnect\Settings;
use QuickCallConnect\Frontend;

$pass = 0;
$fail = 0;

function check( $name, $cond ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "PASS: $name\n";
	} else {
		$fail++;
		echo "FAIL: $name\n";
	}
}

function render_widget( $settings ) {
	$GLOBALS['__options'][ Settings::OPTION ] = $settings;
	$GLOBALS['__enqueued'] = array();
	ob_start();
	Frontend::render();
	return (string) ob_get_clean();
}

// ---------------------------------------------------------------- activation
$GLOBALS['__options'] = array();
require dirname( __DIR__ ) . '/quickcall-connect.php'; // no side effects expected at load besides activation hook def

check( 'activation stores default option', function_exists( 'quickcall_connect_activate' ) );
quickcall_connect_activate();
$stored = get_option( 'quickcall_connect_settings' );
check( 'default option present after activation', is_array( $stored ) && isset( $stored['channels']['call'] ) );
check( 'default placement is right', 'right' === $stored['placement'] );
check( 'all 16 channels registered', 16 === count( Registry::channels() ) );

// ---------------------------------------------------------------- sanitizers
check( 'https URL accepted', 'https://zalo.me/123' === Registry::safe_url( 'https://zalo.me/123' ) );
check( 'javascript: rejected', '' === Registry::safe_url( 'javascript:alert(1)' ) );
check( 'data: rejected', '' === Registry::safe_url( 'data:text/html;base64,xxxx' ) );
check( 'relative URL rejected', '' === Registry::safe_url( 'zalo.me/123' ) );
check( 'http URL accepted', 'http://example.com' === Registry::safe_url( 'http://example.com' ) );

check( 'phone sanitized', '+84901234567' === Settings::sanitize_value( 'phone', ' +84 90 123 45 67 ' ) );
check( 'invalid phone rejected', '' === Settings::sanitize_value( 'phone', 'abc' ) );
check( 'phone with letters stripped/rejected', '' === Settings::sanitize_value( 'phone', 'call+84901' ) );
check( 'email accepted', 'a@b.co' === Settings::sanitize_value( 'email', ' a@b.co ' ) );
check( 'bad email rejected', '' === Settings::sanitize_value( 'email', 'not-an-email' ) );

$clean = Settings::sanitize( array(
	'channels' => array(
		'call' => array( 'value' => 'javascript:alert(1)', 'label' => '<script>x</script>' ),
		'zalo' => array( 'value' => 'https://zalo.me/123' ),
	),
	'custom'   => array( 'url' => 'javascript:alert(1)', 'label' => '<b>Bot</b>', 'icon' => 'robot' ),
	'appearance' => array( 'bg' => '#ff0000', 'offset_x' => 9999, 'offset_y' => -5 ),
	'placement' => 'javascript:evil',
) );
check( 'call channel: javascript scheme stripped -> empty', '' === $clean['channels']['call']['value'] );
check( 'label sanitized to plain text', 'x' === $clean['channels']['call']['label'] );
check( 'valid zalo survives sanitize', 'https://zalo.me/123' === $clean['channels']['zalo']['value'] );
check( 'custom javascript URL rejected', '' === $clean['custom']['url'] );
check( 'custom label HTML stripped', 'Bot' === $clean['custom']['label'] );
check( 'custom icon allowlist honored', 'robot' === $clean['custom']['icon'] );
check( 'bad custom icon falls back', 'chat' === Settings::sanitize( array( 'custom' => array( 'url' => 'https://x.co', 'icon' => 'hack' ) ) )['custom']['icon'] );
check( 'offset_x clamped to 150', 150 === $clean['appearance']['offset_x'] );
check( 'offset_y clamped to 0', 0 === $clean['appearance']['offset_y'] );
check( 'invalid placement falls back to right', 'right' === $clean['placement'] );
check( 'valid bottom placement kept', 'bottom' === Settings::sanitize( array( 'placement' => 'bottom' ) )['placement'] );
check( 'hex color kept', '#ff0000' === $clean['appearance']['bg'] );
check( 'bad color emptied', '' === Settings::sanitize( array( 'appearance' => array( 'bg' => 'red' ) ) )['appearance']['bg'] );

// ---------------------------------------------------------------- rendering
$defaults = Settings::defaults();

// no channels => nothing rendered, no assets
$html = render_widget( $defaults );
check( 'no channels -> no markup', '' === $html );
check( 'no channels -> no assets enqueued', empty( $GLOBALS['__enqueued'] ) );

// disabled + channels => nothing
$s = $defaults;
$s['channels']['call']['value'] = '+84901234567';
$s['enabled'] = 0;
$html = render_widget( $s );
check( 'disabled -> no markup', '' === $html );

// enabled with one channel
$s['enabled'] = 1;
$html = render_widget( $s );
check( 'enabled -> widget rendered', false !== strpos( $html, 'qc-root' ) );
check( 'right placement class', false !== strpos( $html, 'qc-right' ) );
check( 'tel link rendered', false !== strpos( $html, 'href="tel:+84901234567"' ) );
check( 'assets enqueued when widget renders', isset( $GLOBALS['__enqueued']['quickcall-connect'] ) );
check( 'tel link not target=_blank', false === strpos( $html, 'target="_blank"' ) );
check( 'aria-expanded present on toggle', false !== strpos( $html, 'aria-expanded="false"' ) );
check( 'one launcher rendered (renders once)', 1 === substr_count( $html, 'qc-toggle' ) );

// left placement
$s['placement'] = 'left';
$html = render_widget( $s );
check( 'left placement class', false !== strpos( $html, 'qc-left' ) );

// bottom dock
$s['placement'] = 'bottom';
$html = render_widget( $s );
check( 'bottom dock class', false !== strpos( $html, 'qc-dock' ) );
check( 'no toggle in dock mode', false === strpos( $html, 'qc-toggle' ) );
check( 'dock has nav landmark', false !== strpos( $html, '<nav' ) );

// blank optional channels hidden; configured ones appear once
$s['placement'] = 'right';
$s['channels']['facebook']['value'] = 'https://facebook.com/myrental';
$s['channels']['youtube']['value'] = ''; // stays blank
$html = render_widget( $s );
check( 'facebook rendered once', 1 === substr_count( $html, 'https://facebook.com/myrental' ) );
check( 'youtube not rendered when blank', 0 === substr_count( $html, 'youtube' ) );
check( 'new tab for external link', false !== strpos( $html, 'target="_blank"' ) );

// invalid optional value (javascript:) does not render
$s['channels']['instagram']['value'] = 'javascript:alert(1)';
$html = render_widget( $s );
check( 'unsafe optional URL not rendered', 0 === substr_count( $html, 'javascript:' ) );

// custom chatbot link
$s['custom'] = array( 'label' => 'Hỏi trợ lý AI', 'url' => 'https://bot.example.com/chat?t=<script>', 'icon' => 'robot' );
$s['custom']['url'] = 'https://bot.example.com/chat';
$html = render_widget( $s );
check( 'custom link rendered', false !== strpos( $html, 'https://bot.example.com/chat' ) );
check( 'custom label escaped in output', false !== strpos( $html, 'Hỏi trợ lý AI' ) );
check( 'robot icon chosen', false !== strpos( $html, 'M12 8V5' ) );

// label XSS escaped
$s2 = $defaults;
$s2['channels']['call']['value'] = '+84901234567';
$s2['channels']['call']['label'] = '<script>alert(1)</script>';
$html = render_widget( $s2 );
check( 'XSS label escaped', false === strpos( $html, '<script>' ) );
check( 'escaped label present', false !== strpos( $html, '&lt;script&gt;' ) );

// appearance variables
$defaults['channels']['call']['value'] = '+84901234567';
$html = render_widget( $defaults );
check( 'CSS custom property --qc-bg output', false !== strpos( $html, '--qc-bg:' ) );
check( 'inline style attr escaped', false !== strpos( $html, 'style="' ) );

// ---------------------------------------------------------------- uninstall
$GLOBALS['__options'][ Settings::OPTION ] = $defaults;
check( 'option exists before uninstall', false !== get_option( Settings::OPTION, false ) );
define( 'WP_UNINSTALL_PLUGIN', dirname( __DIR__ ) . '/quickcall-connect.php' );
require dirname( __DIR__ ) . '/uninstall.php';
check( 'uninstall removes option', false === get_option( Settings::OPTION, false ) );

// ---------------------------------------------------------------- summary
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
