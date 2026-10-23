<?php
/**
 * Integration test against a REAL WordPress install with the plugin active.
 * Run: wp eval-file qwen-local-writer/tests/wp-integration.php
 * It creates test users/posts in that install — use a disposable site only.
 */
if (!defined('ABSPATH') || !class_exists('QLW\\Ajax')) {
    fwrite(STDERR, "Run with wp eval-file on a site where Qwen Local Writer is active.\n");
    exit(1);
}

final class QLW_Test_Die extends Exception {}

$failures = 0;
$checks = 0;
$check = static function (bool $ok, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures++;
        fwrite(STDERR, "FAIL: $message\n");
    }
};

add_filter('wp_doing_ajax', '__return_true');
add_filter('wp_die_ajax_handler', static fn () => static function () { throw new QLW_Test_Die(); });
add_filter('wp_die_handler', static fn () => static function () { throw new QLW_Test_Die(); });
add_filter('status_header', static function ($header, $code) { $GLOBALS['qlw_status'] = $code; return $header; }, 10, 2);

$user = static function (string $role): int {
    $login = 'qlw_' . $role . '_' . wp_generate_password(6, false);
    return (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(20), 'user_email' => $login . '@example.invalid', 'role' => $role]);
};
$call = static function (string $action, array $post, int $as) {
    wp_set_current_user($as);
    $GLOBALS['qlw_status'] = 200;
    $_POST = wp_slash($post);
    $_REQUEST = $_POST;
    ob_start();
    try {
        do_action('wp_ajax_' . $action);
    } catch (QLW_Test_Die $e) {
    }
    $raw = ob_get_clean();
    $json = json_decode($raw, true);
    return ['status' => $GLOBALS['qlw_status'], 'json' => is_array($json) ? $json : [], 'raw' => $raw];
};

$admin = $user('administrator');
$editor = $user('editor');
$author = $user('author');
$contributor = $user('contributor');
$subscriber = $user('subscriber');
$cat = wp_insert_term('QLW test ' . wp_generate_password(4, false), 'category');
$cat_id = is_wp_error($cat) ? (int) get_option('default_category') : (int) $cat['term_id'];

$sections = [];
for ($i = 1; $i <= 7; $i++) {
    $sentences = [];
    for ($n = 1; $n <= 9; $n++) {
        $sentences[] = "Ở phần $i, ý $n nhắc người thuê kiểm tra điều kiện cụ thể trước khi nhận xe để tránh hiểu lầm.";
    }
    $sections[] = "## Phần $i về thuê xe\n\n" . implode(' ', $sentences);
}
$markdown = "Mở bài giới thiệu ngắn gọn **nội dung chính**.\n\n" . implode("\n\n", $sections) . "\n\n- Mục kiểm tra một\n- Mục kiểm tra hai\n\n## Kết luận\n\nTóm tắt ý chính và gợi ý liên hệ cửa hàng để xác nhận giá.";
$title = 'Kiểm thử Qwen ' . wp_generate_password(8, false);

wp_set_current_user($admin);
$nonce_admin = wp_create_nonce('qlw-save-draft');
$base = ['nonce' => $nonce_admin, 'title' => $title, 'markdown' => $markdown, 'language' => 'vi', 'category' => (string) $cat_id, 'keyword' => 'thuê xe', 'brief' => 'Honda Vision 200.000đ/ngày'];

// Activation state.
$check(is_plugin_active('qwen-local-writer/qwen-local-writer.php'), 'plugin is active');
$check(get_option('qlw_version') === QLW_VERSION, 'activation stored version');
$check(is_array(get_option('qlw_settings')), 'activation stored default settings');

// Permission and CSRF.
$r = $call('qlw_save_draft', $base, $subscriber);
$check($r['status'] === 403 && ($r['json']['data']['code'] ?? '') === 'forbidden', 'subscriber cannot save (403)');
$r = $call('qlw_save_draft', ['nonce' => 'bad'] + $base, $admin);
$check($r['status'] === 403 && ($r['json']['data']['code'] ?? '') === 'nonce', 'invalid nonce rejected (CSRF)');
$r = $call('qlw_save_draft', array_diff_key($base, ['nonce' => 1]), $admin);
$check($r['status'] === 403, 'missing nonce rejected');
wp_set_current_user($editor);
$nonce_editor = wp_create_nonce('qlw-save-draft');
$r = $call('qlw_save_draft', ['nonce' => $nonce_editor] + $base, $admin);
$check($r['status'] === 403, 'nonce of another user is rejected');

// Validation.
$r = $call('qlw_save_draft', ['markdown' => $markdown . "\n<script>alert(1)</script>"] + $base, $admin);
$check($r['status'] === 400 && ($r['json']['data']['code'] ?? '') === 'unsafe', 'script content rejected');
$r = $call('qlw_save_draft', ['markdown' => 'ngắn'] + $base, $admin);
$check($r['status'] === 400, 'too-short content rejected');
$r = $call('qlw_save_draft', ['title' => 'abc'] + $base, $admin);
$check($r['status'] === 400, 'too-short title rejected');
$r = $call('qlw_save_draft', ['category' => '999999'] + $base, $admin);
$check($r['status'] === 400 && ($r['json']['data']['code'] ?? '') === 'category', 'unknown category rejected');

// QA preview does not create posts.
$before = (int) wp_count_posts('post')->draft;
$r = $call('qlw_qa_preview', $base, $admin);
$check(($r['json']['success'] ?? false) && isset($r['json']['data']['qa']['score']), 'QA preview returns a score');
$check((int) wp_count_posts('post')->draft === $before, 'QA preview does not create a post');

// Create draft.
$r = $call('qlw_save_draft', $base + ['generation' => wp_json_encode(['device' => 'wasm', 'dtype' => 'q4', 'evil' => '<b>x</b>', 'tokens' => 1234])], $admin);
$check(($r['json']['success'] ?? false) === true, 'admin saves a draft: ' . substr($r['raw'], 0, 300));
$post_id = (int) ($r['json']['data']['post_id'] ?? 0);
$post = get_post($post_id);
$check($post && $post->post_status === 'draft', 'saved post is a draft, never published');
$check($post && (int) $post->post_author === $admin, 'author is the current user');
$check(has_category($cat_id, $post_id), 'category assigned');
$check(get_post_meta($post_id, '_qlw_markdown', true) === $markdown, 'original Markdown stored unchanged in _qlw_markdown');
$check((int) get_post_meta($post_id, '_qlw_qa_score', true) === (int) $r['json']['data']['qa']['score'], 'QA score stored');
$generation = json_decode((string) get_post_meta($post_id, '_qlw_generation', true), true);
$check(($generation['device'] ?? '') === 'wasm' && !isset($generation['evil']), 'generation meta is whitelisted');
$content = $post ? $post->post_content : '';
$check(str_contains($content, '<!-- wp:heading -->') && str_contains($content, '<!-- wp:list -->') && str_contains($content, '<!-- wp:paragraph -->'), 'content is block markup');
$check(!str_contains($content, '<h1') && !str_contains($content, '<script'), 'no H1 and no script in content');
$blocks = parse_blocks($content);
$names = array_unique(array_filter(array_map(static fn ($b) => $b['blockName'], $blocks)));
sort($names);
$check($names === ['core/heading', 'core/list', 'core/paragraph'], 'parse_blocks finds only core heading/list/paragraph: ' . implode(',', $names));
$rendered = apply_filters('the_content', $content);
$check(str_contains($rendered, '<h2 class="wp-block-heading">Phần 1 về thuê xe</h2>') && str_contains($rendered, '<strong>nội dung chính</strong>'), 'renders headings and inline formatting on the frontend');

// Idempotent update instead of duplicates.
$r2 = $call('qlw_save_draft', ['post_id' => (string) $post_id, 'markdown' => $markdown . "\n\nThêm một câu khi biên tập lại nội dung bài."] + $base, $admin);
$check(($r2['json']['data']['post_id'] ?? 0) === $post_id && ($r2['json']['data']['updated'] ?? false) === true, 'second save updates the same draft');
$check(str_contains(get_post_meta($post_id, '_qlw_markdown', true), 'Thêm một câu'), 'update stored new Markdown');

// Duplicate title guard.
$r3 = $call('qlw_save_draft', $base, $admin);
$check($r3['status'] === 409 && ($r3['json']['data']['code'] ?? '') === 'duplicate' && ($r3['json']['data']['duplicates'][0]['id'] ?? 0) === $post_id, 'duplicate title is detected');
$r3b = $call('qlw_save_draft', ['title' => mb_strtoupper($title)] + $base, $admin);
$check($r3b['status'] === 409, 'duplicate detection is case-insensitive');
$r4 = $call('qlw_save_draft', ['allow_duplicate' => '1'] + $base, $admin);
$dup_id = (int) ($r4['json']['data']['post_id'] ?? 0);
$check($dup_id && $dup_id !== $post_id && get_post_status($dup_id) === 'draft', 'explicit confirmation allows a second draft');

// Never overwrite a post that left draft status.
wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);
$r5 = $call('qlw_save_draft', ['post_id' => (string) $post_id] + $base, $admin);
$check($r5['status'] === 409 && ($r5['json']['data']['code'] ?? '') === 'not_draft', 'published post is never overwritten');
$check(get_post_status($post_id) === 'publish', 'published post status untouched');

// Cannot hijack posts not created by the plugin or owned by others.
$foreign = wp_insert_post(['post_title' => 'Bài thường', 'post_status' => 'draft', 'post_author' => $admin, 'post_content' => 'x']);
$r6 = $call('qlw_save_draft', ['post_id' => (string) $foreign, 'allow_duplicate' => '1'] + $base, $admin);
$check($r6['status'] === 404, 'posts without Qwen metadata cannot be overwritten');
wp_set_current_user($contributor);
$nonce_contrib = wp_create_nonce('qlw-save-draft');
$r7 = $call('qlw_save_draft', ['nonce' => $nonce_contrib, 'post_id' => (string) $dup_id, 'allow_duplicate' => '1'] + $base, $contributor);
$check($r7['status'] === 403, "contributor cannot update an administrator's draft");
$r8 = $call('qlw_save_draft', ['nonce' => $nonce_contrib, 'title' => 'Bài của cộng tác viên ' . wp_generate_password(5, false)] + $base, $contributor);
$contrib_post = (int) ($r8['json']['data']['post_id'] ?? 0);
$check($contrib_post && get_post_status($contrib_post) === 'draft' && (int) get_post_field('post_author', $contrib_post) === $contributor, 'contributor can create own draft');
$check(!current_user_can('publish_posts') && get_post_status($contrib_post) === 'draft', 'contributor draft stays unpublished');

// Kses for users without unfiltered_html: content still safe and intact.
$check(str_contains((string) get_post_field('post_content', $contrib_post), '<!-- wp:heading -->'), 'block comments survive kses for contributors');

// Admin screen access and assets.
foreach ([[$subscriber, false], [$contributor, true], [$admin, true]] as [$who, $allowed]) {
    wp_set_current_user($who);
    ob_start();
    $died = false;
    try {
        \QLW\Plugin::render();
    } catch (QLW_Test_Die $e) {
        $died = true;
    }
    $html = ob_get_clean();
    $check($allowed ? (!$died && str_contains($html, 'id="qlw-app"')) : $died, 'admin screen access for user ' . $who . ' is ' . ($allowed ? 'allowed' : 'denied'));
    if ($allowed) {
        $check(!preg_match('#https?://(?:cdn\.|unpkg|esm\.sh)#i', $html), 'admin screen references no CDN');
        $check(str_contains($html, 'model_source') === ($who === $admin), 'advanced settings shown only to administrators');
    }
}
wp_set_current_user($admin);
set_current_screen('toplevel_page_qlw-local');
do_action('admin_enqueue_scripts', 'toplevel_page_qlw-local');
ob_start();
wp_script_modules()->print_import_map();
wp_script_modules()->print_enqueued_script_modules();
$modules = ob_get_clean();
$check(str_contains($modules, 'assets/local-writer.js') && str_contains($modules, '"@qlw/core"'), 'script modules enqueued with import map');
$check(wp_style_is('qlw-admin', 'enqueued'), 'admin stylesheet enqueued');

// Settings handler requires manage_options and a nonce.
wp_set_current_user($editor);
$_POST = ['model_source' => 'self'];
$_REQUEST = $_POST;
try {
    \QLW\Plugin::save_settings();
    $check(false, 'editor must not change settings');
} catch (QLW_Test_Die $e) {
    $check(\QLW\Plugin::settings()['model_source'] === 'huggingface', 'editor cannot change settings');
}

// Deactivation keeps data.
deactivate_plugins('qwen-local-writer/qwen-local-writer.php');
$check(get_post_status($dup_id) === 'draft' && get_post_meta($dup_id, '_qlw_markdown', true) !== '', 'deactivation keeps drafts and meta');
$check(is_array(get_option('qlw_settings')), 'deactivation keeps settings');
activate_plugin('qwen-local-writer/qwen-local-writer.php');
$check(is_plugin_active('qwen-local-writer/qwen-local-writer.php'), 'reactivation works');

if ($failures) {
    fwrite(STDERR, "WordPress integration: $failures of $checks checks failed\n");
    exit(1);
}
echo "WordPress integration (WP " . get_bloginfo('version') . ", PHP " . PHP_VERSION . "): $checks checks passed\n";
