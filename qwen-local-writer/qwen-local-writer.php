<?php
/**
 * Plugin Name: Qwen Local Writer — No Commercial API (Beta)
 * Description: Viết bản nháp WordPress ~1.500 từ bằng Qwen3-0.6B mã nguồn mở chạy trong trình duyệt quản trị (WebGPU hoặc WASM). Không API AI trả phí, không API key, không suy luận trên máy chủ, không tự xuất bản.
 * Version: 0.2.0-beta
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Nguyễn Tú WordPress Tools
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: qwen-local-writer
 *
 * @package QwenLocalWriter
 */
defined('ABSPATH') || exit;

define('QLW_FILE', __FILE__);
define('QLW_DIR', plugin_dir_path(__FILE__));
define('QLW_VERSION', '0.2.0-beta');

require_once QLW_DIR . 'includes/class-quality.php';
require_once QLW_DIR . 'includes/class-markdown.php';
require_once QLW_DIR . 'includes/class-plugin.php';
require_once QLW_DIR . 'includes/class-ajax.php';

register_activation_hook(__FILE__, [\QLW\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\QLW\Plugin::class, 'deactivate']);

\QLW\Plugin::boot();
