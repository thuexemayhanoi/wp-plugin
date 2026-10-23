<?php
/**
 * Authenticated AJAX endpoints. Posts are only ever created or updated as drafts.
 *
 * @package QwenLocalWriter
 */
namespace QLW;

defined('ABSPATH') || exit;

final class Ajax {
    private const GENERATION_KEYS = ['device', 'dtype', 'model', 'revision', 'tokens', 'seconds', 'calls', 'retries', 'stopped', 'version'];

    private static function guard(): void {
        if (!current_user_can(Plugin::CAP)) {
            wp_send_json_error(['code' => 'forbidden', 'message' => 'Không có quyền tạo bài viết.'], 403);
        }
        $nonce = isset($_POST['nonce']) && is_string($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'qlw-save-draft')) {
            wp_send_json_error(['code' => 'nonce', 'message' => 'Phiên xác thực đã hết hạn. Tải lại trang (nội dung được giữ trong bản khôi phục).'], 403);
        }
    }

    private static function text(string $key, int $max): string {
        $value = isset($_POST[$key]) && is_string($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
        return mb_substr(wp_check_invalid_utf8($value), 0, $max);
    }

    /** @return array{title:string,markdown:string,language:string,keyword:string,brief:string} */
    private static function input(): array {
        $raw_markdown = isset($_POST['markdown']) && is_string($_POST['markdown']) ? wp_unslash($_POST['markdown']) : '';
        if (mb_strlen($raw_markdown) > Markdown::MAX_CHARS) {
            wp_send_json_error(['code' => 'too_long', 'message' => 'Nội dung vượt quá 250.000 ký tự.'], 400);
        }
        $input = [
            'title' => sanitize_text_field(self::text('title', 400)),
            'markdown' => str_replace("\0", '', wp_check_invalid_utf8($raw_markdown)),
            'language' => self::text('language', 5) === 'en' ? 'en' : 'vi',
            'keyword' => sanitize_text_field(self::text('keyword', 160)),
            'brief' => sanitize_textarea_field(self::text('brief', 16000)),
        ];
        if (mb_strlen($input['title']) < 5 || mb_strlen($input['title']) > 200) {
            wp_send_json_error(['code' => 'title', 'message' => 'Tiêu đề cần 5–200 ký tự.'], 400);
        }
        if (mb_strlen(trim($input['markdown'])) < 120) {
            wp_send_json_error(['code' => 'too_short', 'message' => 'Nội dung quá ngắn (tối thiểu 120 ký tự).'], 400);
        }
        if (Quality::is_unsafe($input['markdown'])) {
            wp_send_json_error(['code' => 'unsafe', 'message' => 'Nội dung chứa HTML/mã không an toàn (script, iframe, javascript:…); đã từ chối lưu.'], 400);
        }
        return $input;
    }

    public static function qa_preview(): void {
        self::guard();
        $input = self::input();
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        wp_send_json_success(['qa' => self::assess($input, $post_id)]);
    }

    /** QA with the WordPress-dependent context: post title and similar existing posts. */
    private static function assess(array $input, int $post_id): array {
        return Quality::assess($input['markdown'], 1500, $input['language'], $input['brief'], $input['keyword'], [
            'title' => $input['title'],
            'similar' => self::similar_posts($input['title'], $input['markdown'], $post_id, $input['keyword']),
        ]);
    }

    /** @return array<string,true> */
    private static function shingles(string $text, int $size = 5): array {
        $words = Quality::words(mb_strtolower($text));
        $out = [];
        for ($i = 0, $n = count($words) - $size; $i <= $n; $i++) {
            $out[implode(' ', array_slice($words, $i, $size))] = true;
        }
        return $out;
    }

    /**
     * Existing posts (any status the current user may read) whose title or text is close to the
     * new draft: title word overlap (Jaccard) and the share of the draft's 5-word phrases that
     * already appear in the post. Read-only; at most 20 candidates found by WordPress search.
     *
     * @return list<array{id:int,title:string,status:string,title_similarity:float,content_similarity:float}>
     */
    public static function similar_posts(string $title, string $markdown, int $exclude = 0, string $keyword = ''): array {
        $title_words = array_values(array_filter(Quality::words(Quality::key($title)), static fn (string $w): bool => mb_strlen($w) >= 3));
        $searches = array_filter(array_unique([trim($keyword), implode(' ', array_slice($title_words, 0, 3))]));
        $ids = [];
        foreach ($searches as $search) {
            $query = new \WP_Query([
                'post_type' => 'post',
                'post_status' => ['publish', 'future', 'draft', 'pending', 'private'],
                's' => $search,
                'posts_per_page' => 20,
                'fields' => 'ids',
                'no_found_rows' => true,
                'ignore_sticky_posts' => true,
                'post__not_in' => $exclude ? [$exclude] : [],
            ]);
            $ids = array_merge($ids, array_map('intval', $query->posts));
        }
        $draft = self::shingles(mb_substr($markdown, 0, 60000));
        $own_title = array_unique(Quality::words(Quality::key($title)));
        $out = [];
        foreach (array_slice(array_unique($ids), 0, 20) as $id) {
            if ($id === $exclude || !current_user_can('read_post', $id)) {
                continue;
            }
            $post = get_post($id);
            if (!$post) {
                continue;
            }
            $other_title = array_unique(Quality::words(Quality::key($post->post_title)));
            $union = count(array_unique(array_merge($own_title, $other_title)));
            $title_similarity = $union ? count(array_intersect($own_title, $other_title)) / $union : 0.0;
            $text = (string) get_post_meta($id, '_qlw_markdown', true);
            if ($text === '') {
                $text = wp_strip_all_tags(strip_shortcodes((string) $post->post_content));
            }
            $existing = self::shingles(mb_substr($text, 0, 60000));
            $content_similarity = $draft ? count(array_intersect_key($draft, $existing)) / count($draft) : 0.0;
            if ($title_similarity >= 0.5 || $content_similarity >= 0.1) {
                $out[] = [
                    'id' => $id,
                    'title' => $post->post_title,
                    'status' => $post->post_status,
                    'title_similarity' => round($title_similarity, 2),
                    'content_similarity' => round($content_similarity, 2),
                ];
            }
        }
        usort($out, static fn (array $a, array $b): int => $b['content_similarity'] <=> $a['content_similarity'] ?: $b['title_similarity'] <=> $a['title_similarity']);
        return array_slice($out, 0, 5);
    }

    /**
     * Focus-keyword meta of SEO plugins that are active (Yoast SEO, Rank Math). Filterable.
     *
     * @return list<string>
     */
    public static function focus_keyword_keys(): array {
        $keys = [];
        if (defined('WPSEO_VERSION')) {
            $keys[] = '_yoast_wpseo_focuskw';
        }
        if (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
            $keys[] = 'rank_math_focus_keyword';
        }
        return array_values(array_filter((array) apply_filters('qlw_focus_keyword_meta_keys', $keys), 'is_string'));
    }

    public static function title_key(string $title): string {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $title)));
    }

    /**
     * Existing posts with the same title. MySQL collations already compare case-insensitively;
     * the _qlw_title_key meta also catches Unicode case variants of drafts made by this plugin
     * on databases whose LOWER() is ASCII-only (e.g. SQLite).
     *
     * @return list<array{id:int,status:string,edit_url:string}>
     */
    public static function duplicates(string $title, int $exclude = 0): array {
        global $wpdb;
        $key = self::title_key($title);
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_qlw_title_key'
             WHERE p.post_type = 'post' AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit') AND p.ID <> %d
               AND (p.post_title = %s OR LOWER(p.post_title) = %s OR m.meta_value = %s)
             GROUP BY p.ID ORDER BY p.ID DESC LIMIT 5",
            $exclude,
            $title,
            $key,
            $key
        ));
        $out = [];
        foreach (array_map('intval', (array) $ids) as $id) {
            $out[] = [
                'id' => $id,
                'status' => (string) get_post_status($id),
                'edit_url' => current_user_can('edit_post', $id) ? (string) get_edit_post_link($id, 'raw') : '',
            ];
        }
        return $out;
    }

    private static function generation_meta(): string {
        $raw = isset($_POST['generation']) && is_string($_POST['generation']) ? json_decode(wp_unslash($_POST['generation']), true) : null;
        $clean = [];
        if (is_array($raw)) {
            foreach (self::GENERATION_KEYS as $key) {
                if (isset($raw[$key]) && is_scalar($raw[$key])) {
                    $clean[$key] = is_bool($raw[$key]) ? ($raw[$key] ? 'yes' : 'no') : mb_substr(sanitize_text_field((string) $raw[$key]), 0, 80);
                }
            }
        }
        return (string) wp_json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    public static function save_draft(): void {
        self::guard();
        $input = self::input();
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $category = isset($_POST['category']) ? absint($_POST['category']) : 0;
        $allow_duplicate = !empty($_POST['allow_duplicate']);

        if ($category) {
            $term = get_term($category, 'category');
            if (!$term || is_wp_error($term)) {
                wp_send_json_error(['code' => 'category', 'message' => 'Danh mục không hợp lệ.'], 400);
            }
            if (!current_user_can('assign_term', $category)) {
                wp_send_json_error(['code' => 'category', 'message' => 'Bạn không có quyền gán danh mục này.'], 403);
            }
        }

        if ($post_id) {
            $existing = get_post($post_id);
            if (!$existing || $existing->post_type !== 'post' || get_post_meta($post_id, '_qlw_markdown', true) === '') {
                wp_send_json_error(['code' => 'not_found', 'message' => 'Không tìm thấy bản nháp Qwen để cập nhật.'], 404);
            }
            if (!current_user_can('edit_post', $post_id)) {
                wp_send_json_error(['code' => 'forbidden', 'message' => 'Không có quyền sửa bài này.'], 403);
            }
            // Editors may edit other people's posts in WordPress, but this tool never overwrites them.
            if ((int) $existing->post_author !== get_current_user_id()) {
                wp_send_json_error(['code' => 'not_owner', 'message' => 'Bản nháp #' . $post_id . ' thuộc người dùng khác; plugin không ghi đè. Bấm "Bắt đầu bài mới" để lưu thành nháp của bạn.'], 403);
            }
            if ($existing->post_status !== 'draft') {
                wp_send_json_error([
                    'code' => 'not_draft',
                    'message' => 'Bài #' . $post_id . ' đã chuyển sang trạng thái "' . $existing->post_status . '"; plugin không ghi đè. Bấm "Bắt đầu bài mới" để lưu thành nháp khác.',
                ], 409);
            }
        }

        if (!$allow_duplicate) {
            $duplicates = self::duplicates($input['title'], $post_id);
            if ($duplicates) {
                wp_send_json_error([
                    'code' => 'duplicate',
                    'message' => 'Đã có bài cùng tiêu đề. Đổi tiêu đề hoặc xác nhận vẫn lưu.',
                    'duplicates' => $duplicates,
                ], 409);
            }
        }

        $report = self::assess($input, $post_id);
        $use_blocks = (bool) apply_filters('qlw_use_block_markup', true);
        $args = [
            'post_type' => 'post',
            'post_status' => 'draft',
            'post_title' => $input['title'],
            'post_content' => $use_blocks ? Markdown::to_blocks($input['markdown']) : Markdown::to_html($input['markdown']),
            'meta_input' => [
                '_qlw_markdown' => $input['markdown'],
                '_qlw_title_key' => self::title_key($input['title']),
                '_qlw_qa_score' => $report['score'],
                '_qlw_qa_status' => $report['ready_for_review'] ? 'READY_FOR_HUMAN_REVIEW' : 'NEEDS_REVISION',
                '_qlw_qa_report' => wp_json_encode([
                    'score' => $report['score'],
                    'groups' => $report['groups'],
                    'notes' => $report['notes'],
                    'unverified' => $report['unverified'],
                    'fact_check' => array_slice($report['fact_check'], 0, 15),
                    'fact_status' => $report['fact_status'],
                ], JSON_UNESCAPED_UNICODE),
                '_qlw_language' => $input['language'],
                '_qlw_keyword' => $input['keyword'],
                '_qlw_brief' => $input['brief'],
                '_qlw_generation' => self::generation_meta(),
                '_qlw_requested_model' => Plugin::MODEL_ID . '@' . Plugin::MODEL_REVISION,
            ],
        ];
        if ($category) {
            $args['post_category'] = [$category];
        }
        if ($post_id) {
            $args['ID'] = $post_id;
            $result = wp_update_post(wp_slash($args), true);
        } else {
            $args['post_author'] = get_current_user_id();
            $result = wp_insert_post(wp_slash($args), true);
        }
        if (is_wp_error($result) || !$result) {
            wp_send_json_error(['code' => 'insert', 'message' => 'Không lưu được bản nháp WordPress' . (is_wp_error($result) ? ': ' . $result->get_error_message() : '.')], 500);
        }
        if ($post_id) {
            // The text changed, so an earlier editorial sign-off no longer applies.
            delete_post_meta($post_id, '_qlw_editorial_review');
        }
        if ($input['keyword'] !== '') {
            foreach (self::focus_keyword_keys() as $meta_key) {
                // Never replace a focus keyword an editor already set.
                if ((string) get_post_meta((int) $result, $meta_key, true) === '') {
                    update_post_meta((int) $result, $meta_key, $input['keyword']);
                }
            }
        }
        $saved = get_post((int) $result);
        wp_send_json_success([
            'post_id' => (int) $result,
            'updated' => (bool) $post_id,
            'status' => $saved ? $saved->post_status : 'draft',
            'edit_url' => (string) get_edit_post_link((int) $result, 'raw'),
            'qa' => $report,
        ]);
    }
}
