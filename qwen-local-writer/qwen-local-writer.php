<?php
/**
 * Plugin Name: Qwen Local Writer — No Commercial API (Beta)
 * Description: Write editable WordPress drafts with Qwen3 in an administrator's WebGPU browser. No paid AI API or server inference.
 * Version: 0.1.0-beta
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Nguyễn Tú WordPress Tools
 * License: GPL-3.0-or-later
 * Text Domain: qwen-local-writer
 */
defined('ABSPATH') || exit;
define('QLW_FILE', __FILE__);
define('QLW_VERSION', '0.1.0-beta');
require_once __DIR__ . '/includes/class-quality.php';

final class QLW_Plugin {
    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('wp_ajax_qlw_save_draft', [self::class, 'save_draft']);
    }

    public static function menu(): void {
        add_menu_page(
            'Qwen Local Writer', 'Qwen Local Writer', 'edit_posts', 'qlw-local',
            [self::class, 'render'], 'dashicons-edit-large', 66
        );
    }

    public static function assets(string $hook): void {
        if ($hook !== 'toplevel_page_qlw-local' || !current_user_can('edit_posts')) {
            return;
        }
        if (function_exists('wp_enqueue_script_module')) {
            wp_enqueue_script_module('qlw-browser', plugins_url('assets/local-writer.js', QLW_FILE), [], QLW_VERSION);
        }
    }

    public static function render(): void {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You are not allowed to create posts.', 'qwen-local-writer'));
        }
        echo '<div class="wrap" id="qlw-app" data-ajax="' . esc_url(admin_url('admin-ajax.php')) . '" data-nonce="' . esc_attr(wp_create_nonce('qlw-save-draft')) . '">';
        echo '<h1>Qwen Local Writer <small>Beta — không dùng API AI thương mại</small></h1>';
        echo '<div class="notice notice-warning inline"><p><strong>Chỉ thử trên staging trước khi dùng production.</strong> Qwen tải về và chạy trên trình duyệt quản trị có WebGPU. Giữ tab mở cho tới khi viết xong. iPhone/Cloud Browser có thể không hỗ trợ. Thư viện JS thử nghiệm hiện lấy từ CDN — cần tự lưu và kiểm tra integrity trước khi phát hành ổn định. Nội dung có thể sai: luôn kiểm tra thực tế trước khi đăng.</p></div>';
        echo '<p><label for="qlw-title"><strong>Tiêu đề bài</strong></label><br><input style="width:min(780px,100%)" id="qlw-title" type="text" maxlength="250" required placeholder="Nhập tiêu đề bài viết"></p>';
        echo '<p><label for="qlw-keyword">Từ khóa chính</label><br><input style="width:min(500px,100%)" id="qlw-keyword" maxlength="160" type="text"></p>';
        echo '<p><label for="qlw-language">Ngôn ngữ</label><br><select id="qlw-language"><option value="vi">Tiếng Việt</option><option value="en">English</option></select></p>';
        echo '<p><label for="qlw-category">Danh mục</label><br>';
        wp_dropdown_categories([
            'show_option_none' => '— Chọn danh mục —',
            'option_none_value' => '0',
            'taxonomy' => 'category',
            'name' => 'qlw-category',
            'id' => 'qlw-category',
            'hide_empty' => false,
            'selected' => (int)get_option('default_category'),
        ]);
        echo '</p>';
        echo '<p><label for="qlw-facts"><strong>Brief / nguồn sự thật đã xác minh</strong></label><br>';
        echo '<textarea id="qlw-facts" rows="6" style="width:min(880px,100%)" maxlength="16000" placeholder="Nhập dữ liệu thật, phạm vi chủ đề, đường dẫn đã kiểm chứng. Không có nguồn thì không bịa giá hoặc luật."></textarea></p>';
        echo '<p><button type="button" class="button" id="qlw-load">1. Tải Qwen vào trình duyệt</button> ';
        echo '<button type="button" class="button button-primary" id="qlw-generate" disabled>2. Viết nháp khoảng 1.500 từ</button> ';
        echo '<button type="button" class="button" id="qlw-stop" disabled>Dừng sau đoạn hiện tại</button></p>';
        echo '<p id="qlw-progress" role="status" aria-live="polite">Chưa tải Qwen.</p>';
        echo '<p><label for="qlw-markdown"><strong>Nội dung Markdown — có thể sửa thủ công trước khi lưu</strong></label></p>';
        echo '<textarea id="qlw-markdown" rows="25" style="width:min(1000px,100%);font-family:monospace" maxlength="250000"></textarea>';
        echo '<p id="qlw-count" aria-live="polite">0 từ</p>';
        echo '<p><button type="button" class="button button-primary" id="qlw-save">3. Lưu bản nháp vào WordPress (không xuất bản)</button></p>';
        echo '<div id="qlw-result" role="status" aria-live="polite"></div>';
        echo '<p>QA 70/100 là <strong>đánh giá kỹ thuật</strong> theo độ dài, cấu trúc, lặp nội dung và an toàn; không xác nhận độ chính xác, chất lượng SEO hay nguồn tin. Nếu điểm thấp vẫn lưu nháp với nhãn cần sửa.</p>';
        echo '</div>';
    }

    private static function content_html(string $markdown): string {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $html = '';
        $para = [];
        $flush = static function () use (&$para, &$html): void {
            if ($para) {
                $html .= '<p>' . esc_html(implode(' ', $para)) . '</p>' . "\n";
                $para = [];
            }
        };
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                $flush();
                continue;
            }
            if (preg_match('/^(#{2,4})\s+(.+)$/u', $line, $m)) {
                $flush();
                $level = strlen($m[1]);
                $html .= '<h' . $level . '>' . esc_html($m[2]) . '</h' . $level . '>' . "\n";
                continue;
            }
            // Untrusted Markdown is always rendered as escaped text, never raw HTML.
            $para[] = $line;
        }
        $flush();
        return $html;
    }

    public static function save_draft(): void {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Không có quyền tạo bài viết.'], 403);
        }
        $nonce = isset($_POST['nonce']) && is_string($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'qlw-save-draft')) {
            wp_send_json_error(['message' => 'Phiên xác thực đã hết hạn. Tải lại trang.'], 403);
        }
        $title = isset($_POST['title']) && is_string($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $markdown = isset($_POST['markdown']) && is_string($_POST['markdown']) ? wp_unslash($_POST['markdown']) : '';
        $category = isset($_POST['category']) ? absint($_POST['category']) : 0;
        $language = isset($_POST['language']) && $_POST['language'] === 'en' ? 'en' : 'vi';
        if (mb_strlen($title) < 5 || mb_strlen($title) > 250 || mb_strlen($markdown) < 120 || mb_strlen($markdown) > 250000) {
            wp_send_json_error(['message' => 'Tiêu đề hoặc nội dung không hợp lệ (5–250 ký tự tiêu đề, nội dung 120–250.000 ký tự).'], 400);
        }
        if (preg_match('/<\s*(?:script|iframe|style|form|svg)\b|<\?php|javascript\s*:/iu', $markdown)) {
            wp_send_json_error(['message' => 'Nội dung chứa mã không an toàn; đã từ chối lưu.'], 400);
        }
        if ($category && (!term_exists($category, 'category') || is_wp_error(get_term($category, 'category')))) {
            wp_send_json_error(['message' => 'Danh mục không hợp lệ.'], 400);
        }
        $markdown = wp_check_invalid_utf8($markdown);
        $report = \QLW\Quality::assess($markdown, 1500);
        $html = self::content_html($markdown);
        $args = [
            'post_type' => 'post',
            'post_status' => 'draft',
            'post_title' => $title,
            'post_content' => $html,
            'post_author' => get_current_user_id(),
            'meta_input' => [
                '_qlw_markdown' => $markdown,
                '_qlw_qa_score' => $report['score'],
                '_qlw_qa_status' => $report['ready_for_review'] ? 'READY_FOR_HUMAN_REVIEW' : 'NEEDS_REVISION',
                '_qlw_language' => $language,
                '_qlw_requested_model' => 'onnx-community/Qwen3-0.6B-ONNX',
            ],
        ];
        if ($category) {
            $args['post_category'] = [$category];
        }
        $post_id = wp_insert_post(wp_slash($args), true);
        if (is_wp_error($post_id) || !$post_id) {
            wp_send_json_error(['message' => 'Không lưu được bản nháp WordPress.'], 500);
        }
        wp_send_json_success([
            'post_id' => (int)$post_id,
            'edit_url' => get_edit_post_link((int)$post_id, 'raw'),
            'qa' => $report,
            'status' => 'draft',
        ]);
    }
}
QLW_Plugin::boot();
