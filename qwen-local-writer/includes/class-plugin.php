<?php
/**
 * Admin screen, assets, settings and the post-editor metabox.
 *
 * @package QwenLocalWriter
 */
namespace QLW;

defined('ABSPATH') || exit;

final class Plugin {
    public const SLUG = 'qlw-local';
    public const OPTION = 'qlw_settings';
    public const CAP = 'edit_posts';
    public const MODEL_ID = 'onnx-community/Qwen3-0.6B-ONNX';
    /** Hugging Face commit of onnx-community/Qwen3-0.6B-ONNX the plugin was tested with. */
    public const MODEL_REVISION = '58ca03a5e7b354b480b1a82b34bde8dc7b854cc0';
    public const MODEL_FILES = [
        'config.json',
        'generation_config.json',
        'tokenizer.json',
        'tokenizer_config.json',
        'onnx/model_q4f16.onnx',
        'onnx/model_q4.onnx',
    ];

    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('admin_post_qlw_save_settings', [self::class, 'save_settings']);
        add_action('add_meta_boxes_post', [self::class, 'metabox']);
        add_action('wp_ajax_qlw_save_draft', [Ajax::class, 'save_draft']);
        add_action('wp_ajax_qlw_qa_preview', [Ajax::class, 'qa_preview']);
    }

    public static function activate(): void {
        if (version_compare(PHP_VERSION, '8.1', '<')) {
            deactivate_plugins(plugin_basename(QLW_FILE));
            wp_die(esc_html__('Qwen Local Writer cần PHP 8.1 trở lên.', 'qwen-local-writer'));
        }
        // add_option never overwrites an existing value, so re-activation keeps settings.
        add_option(self::OPTION, self::defaults(), '', false);
        update_option('qlw_version', QLW_VERSION, false);
    }

    /** Deactivation intentionally keeps settings, drafts and post meta. */
    public static function deactivate(): void {
    }

    public static function defaults(): array {
        return ['model_source' => 'huggingface', 'isolation' => 1];
    }

    public static function settings(): array {
        $saved = get_option(self::OPTION, []);
        $settings = array_merge(self::defaults(), is_array($saved) ? $saved : []);
        $settings['model_source'] = $settings['model_source'] === 'self' ? 'self' : 'huggingface';
        $settings['isolation'] = (int) !empty($settings['isolation']);
        return $settings;
    }

    public static function self_hosted_base(): string {
        $uploads = wp_get_upload_dir();
        return trailingslashit($uploads['baseurl']) . 'qwen-local-writer/models/';
    }

    public static function self_hosted_dir(): string {
        $uploads = wp_get_upload_dir();
        return trailingslashit($uploads['basedir']) . 'qwen-local-writer/models/';
    }

    public static function menu(): void {
        $hook = add_menu_page(
            'Qwen Local Writer',
            'Qwen Local Writer',
            self::CAP,
            self::SLUG,
            [self::class, 'render'],
            'dashicons-edit-large',
            66
        );
        if ($hook) {
            add_action('load-' . $hook, [self::class, 'isolation_headers']);
        }
    }

    /**
     * Cross-origin isolation lets ONNX Runtime use several CPU threads in WASM mode.
     * Only this screen gets the headers; "credentialless" keeps no-cors images working.
     */
    public static function isolation_headers(): void {
        $enabled = (bool) apply_filters('qlw_cross_origin_isolation', (bool) self::settings()['isolation']);
        if ($enabled && !headers_sent()) {
            header('Cross-Origin-Opener-Policy: same-origin');
            header('Cross-Origin-Embedder-Policy: credentialless');
        }
    }

    public static function assets(string $hook): void {
        if ($hook !== 'toplevel_page_' . self::SLUG || !current_user_can(self::CAP)) {
            return;
        }
        wp_enqueue_style('qlw-admin', plugins_url('assets/local-writer.css', QLW_FILE), [], QLW_VERSION);
        if (function_exists('wp_register_script_module')) {
            wp_register_script_module('@qlw/core', plugins_url('assets/qlw-core.js', QLW_FILE), [], QLW_VERSION);
            wp_enqueue_script_module('@qlw/local-writer', plugins_url('assets/local-writer.js', QLW_FILE), ['@qlw/core'], QLW_VERSION);
        }
    }

    public static function client_config(): array {
        $settings = self::settings();
        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('qlw-save-draft'),
            'workerUrl' => add_query_arg('ver', QLW_VERSION, plugins_url('assets/qwen-worker.js', QLW_FILE)),
            'model' => ['id' => self::MODEL_ID, 'revision' => self::MODEL_REVISION],
            'selfHostedBase' => $settings['model_source'] === 'self' ? self::self_hosted_base() : '',
            'cacheKey' => 'qlw:' . get_current_blog_id() . ':' . get_current_user_id(),
            'version' => QLW_VERSION,
            'target' => 1500,
        ];
    }

    public static function render(): void {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Bạn không có quyền tạo bài viết.', 'qwen-local-writer'));
        }
        $config = self::client_config();
        $settings = self::settings();
        include QLW_DIR . 'includes/view-admin.php';
    }

    public static function save_settings(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Chỉ quản trị viên được đổi cài đặt.', 'qwen-local-writer'), 403);
        }
        check_admin_referer('qlw-settings');
        $source = isset($_POST['model_source']) && $_POST['model_source'] === 'self' ? 'self' : 'huggingface';
        update_option(self::OPTION, [
            'model_source' => $source,
            'isolation' => empty($_POST['isolation']) ? 0 : 1,
        ], false);
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'qlw-saved' => 1], admin_url('admin.php')));
        exit;
    }

    /** Which self-hosted files exist on disk (no network request is made). */
    public static function self_hosted_status(): array {
        $dir = self::self_hosted_dir() . self::MODEL_ID . '/';
        $status = [];
        foreach (self::MODEL_FILES as $file) {
            $status[$file] = is_file($dir . $file);
        }
        return $status;
    }

    public static function metabox(\WP_Post $post): void {
        if (get_post_meta($post->ID, '_qlw_markdown', true) === '') {
            return;
        }
        add_meta_box('qlw-source', 'Qwen Local Writer — Markdown & QA', [self::class, 'render_metabox'], 'post', 'normal', 'default');
    }

    public static function render_metabox(\WP_Post $post): void {
        $markdown = (string) get_post_meta($post->ID, '_qlw_markdown', true);
        $score = get_post_meta($post->ID, '_qlw_qa_score', true);
        $status = (string) get_post_meta($post->ID, '_qlw_qa_status', true);
        $report = json_decode((string) get_post_meta($post->ID, '_qlw_qa_report', true), true);
        $generation = json_decode((string) get_post_meta($post->ID, '_qlw_generation', true), true);
        echo '<p><strong>QA kỹ thuật:</strong> ' . esc_html((string) $score) . '/100 — ' . esc_html($status) . '. ';
        echo 'Điểm này không xác minh sự thật, giá, luật hay nguồn; cần biên tập thủ công trước khi đăng.</p>';
        if (is_array($report) && !empty($report['notes'])) {
            echo '<ul style="list-style:disc;padding-left:1.5em">';
            foreach ((array) $report['notes'] as $note) {
                echo '<li>' . esc_html((string) $note) . '</li>';
            }
            echo '</ul>';
        }
        if (is_array($generation) && $generation) {
            $parts = [];
            foreach ($generation as $key => $value) {
                $parts[] = $key . ': ' . (is_scalar($value) ? (string) $value : '');
            }
            echo '<p><small>Thông tin tạo bài (trình duyệt tự khai báo): ' . esc_html(implode(' · ', $parts)) . '</small></p>';
        }
        echo '<p><label for="qlw-md-source"><strong>Markdown gốc</strong> (chỉ đọc; nội dung hiển thị là HTML đã lọc phía trên)</label></p>';
        echo '<textarea id="qlw-md-source" readonly rows="14" style="width:100%;font-family:monospace">' . esc_textarea($markdown) . '</textarea>';
    }
}
