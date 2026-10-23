<?php
/**
 * Minimal WordPress stand-ins for offline unit tests. Real WordPress behaviour
 * (kses, nonces, capabilities, wp_insert_post) is covered by tests/wp-integration.php.
 */
define('ABSPATH', __DIR__ . '/');

function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_url($url, $protocols = null) {
    $url = trim((string) $url);
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, $protocols ?: ['http', 'https'], true) ? str_replace(['"', "'", '<', '>'], '', $url) : '';
}
function wp_kses_post($html) { return $html; }

$GLOBALS['qlw_failures'] = 0;
$GLOBALS['qlw_checks'] = 0;
function check(bool $ok, string $message): void {
    $GLOBALS['qlw_checks']++;
    if (!$ok) {
        $GLOBALS['qlw_failures']++;
        fwrite(STDERR, "FAIL: $message\n");
    }
}
function finish(string $suite): void {
    if ($GLOBALS['qlw_failures']) {
        fwrite(STDERR, "$suite: {$GLOBALS['qlw_failures']} of {$GLOBALS['qlw_checks']} checks failed\n");
        exit(1);
    }
    echo "$suite: {$GLOBALS['qlw_checks']} checks passed\n";
}
