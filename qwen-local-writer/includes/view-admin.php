<?php
/**
 * Admin screen markup. Variables: $config (array), $settings (array).
 *
 * @package QwenLocalWriter
 */
defined('ABSPATH') || exit;
$qlw_is_admin = current_user_can('manage_options');
?>
<div class="wrap qlw" id="qlw-app" data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
  <h1 class="qlw-title">Qwen Local Writer <span class="qlw-badge">Beta <?php echo esc_html(QLW_VERSION); ?></span></h1>
  <p class="qlw-lead">Viết bản nháp khoảng 1.500 từ bằng mô hình mã nguồn mở <strong>Qwen3-0.6B</strong> chạy ngay trong trình duyệt của bạn. Không dùng API AI trả phí, không cần API key. Bài luôn được lưu ở trạng thái <strong>Nháp</strong>.</p>
  <?php if (isset($_GET['qlw-saved'])) : // phpcs:ignore WordPress.Security.NonceVerification ?>
    <div class="notice notice-success is-dismissible"><p>Đã lưu cài đặt.</p></div>
  <?php endif; ?>
  <noscript><div class="notice notice-error"><p>Cần bật JavaScript để dùng Qwen Local Writer.</p></div></noscript>
  <div class="notice notice-warning inline qlw-warning"><p><strong>Lưu ý:</strong> Qwen 0.6B là mô hình nhỏ, có thể viết sai hoặc lặp ý. Luôn kiểm tra và sửa nội dung, đặc biệt giá, chính sách, luật và thông tin doanh nghiệp, trước khi đăng. Giữ tab này mở trong lúc tải model và viết bài.</p></div>

  <div class="qlw-grid">
    <section class="qlw-card" aria-labelledby="qlw-step-env">
      <h2 id="qlw-step-env"><span class="qlw-step">1</span> Thiết bị</h2>
      <ul class="qlw-env" id="qlw-env"><li>Đang kiểm tra WebGPU, bộ nhớ và trình duyệt…</li></ul>
      <p>
        <label for="qlw-device">Chế độ chạy</label>
        <select id="qlw-device">
          <option value="auto">Tự động (WebGPU nếu có, nếu không dùng WASM)</option>
          <option value="webgpu">Chỉ WebGPU (GPU)</option>
          <option value="wasm">Chỉ WASM (CPU, chậm hơn)</option>
        </select>
      </p>
      <p class="qlw-actions">
        <button type="button" class="button button-primary" id="qlw-load" disabled>Tải Qwen vào trình duyệt</button>
        <button type="button" class="button" id="qlw-clear-cache">Xóa cache model</button>
      </p>
      <div class="qlw-progress" hidden id="qlw-download-wrap">
        <progress id="qlw-download" max="100" value="0"></progress>
        <span id="qlw-download-text">0%</span>
      </div>
      <p id="qlw-model-status" class="qlw-status" role="status" aria-live="polite">Chưa tải model. Lần đầu tải khoảng 0,6–0,9 GB; các lần sau dùng cache của trình duyệt.</p>
    </section>

    <section class="qlw-card" aria-labelledby="qlw-step-input">
      <h2 id="qlw-step-input"><span class="qlw-step">2</span> Thông tin bài viết</h2>
      <p><label for="qlw-title-input">Tiêu đề bài <span aria-hidden="true">*</span></label>
        <input id="qlw-title-input" class="qlw-input" type="text" maxlength="200" required placeholder="Ví dụ: Kinh nghiệm thuê xe máy ở Hà Nội cho người mới"></p>
      <p><label for="qlw-keyword">Từ khóa chính</label>
        <input id="qlw-keyword" class="qlw-input" type="text" maxlength="160"></p>
      <div class="qlw-row">
        <p><label for="qlw-language">Ngôn ngữ bài</label>
          <select id="qlw-language"><option value="vi">Tiếng Việt</option><option value="en">English</option></select></p>
        <p><label for="qlw-category">Danh mục</label>
          <?php
          wp_dropdown_categories([
              'show_option_none' => '— Không chọn —',
              'option_none_value' => '0',
              'taxonomy' => 'category',
              'name' => 'qlw-category',
              'id' => 'qlw-category',
              'hide_empty' => false,
              'hierarchical' => true,
              'selected' => (int) get_option('default_category'),
          ]);
          ?></p>
      </div>
      <p><label for="qlw-facts">Dữ liệu đã xác minh (brief)</label>
        <textarea id="qlw-facts" class="qlw-input" rows="6" maxlength="16000" placeholder="Dán thông tin thật: tên thương hiệu, địa chỉ, giá, chính sách, ý chính cần nói. Qwen chỉ được dùng số liệu có ở đây."></textarea>
        <small id="qlw-facts-count">0/16.000 ký tự (6.000 ký tự đầu được đưa vào prompt)</small></p>
    </section>

    <section class="qlw-card qlw-wide" aria-labelledby="qlw-step-write">
      <h2 id="qlw-step-write"><span class="qlw-step">3</span> Viết bài</h2>
      <p class="qlw-actions">
        <button type="button" class="button button-primary button-hero" id="qlw-generate" disabled>Viết nháp ~1.500 từ</button>
        <button type="button" class="button" id="qlw-stop" disabled>Dừng ngay</button>
      </p>
      <div class="qlw-progress" hidden id="qlw-write-wrap">
        <progress id="qlw-write" max="100" value="0"></progress>
        <span id="qlw-write-text">0%</span>
      </div>
      <p id="qlw-progress" class="qlw-status" role="status" aria-live="polite">Tải Qwen trước khi viết.</p>
      <p><label for="qlw-markdown">Nội dung Markdown — có thể sửa trước khi lưu</label></p>
      <textarea id="qlw-markdown" class="qlw-editor" rows="22" maxlength="250000" spellcheck="true"></textarea>
      <p id="qlw-count" class="qlw-muted" aria-live="polite">0 từ</p>
    </section>

    <section class="qlw-card qlw-wide" aria-labelledby="qlw-step-save">
      <h2 id="qlw-step-save"><span class="qlw-step">4</span> Kiểm tra & lưu nháp</h2>
      <p class="qlw-actions">
        <button type="button" class="button" id="qlw-qa">Chấm QA kỹ thuật</button>
        <button type="button" class="button button-primary" id="qlw-save">Lưu bản nháp (không xuất bản)</button>
        <button type="button" class="button-link" id="qlw-new" hidden>Bắt đầu bài mới</button>
      </p>
      <div id="qlw-result" class="qlw-result" role="status" aria-live="polite"></div>
      <p class="qlw-muted">QA 70/100 là <strong>điểm kỹ thuật nội bộ</strong> (độ dài, H2, lặp, ngôn ngữ, chữ Hán lẫn vào, chi tiết ngoài brief, mã nguy hiểm). Điểm này không chứng nhận nội dung chính xác hay bảo đảm SEO. Bài chưa đạt vẫn lưu nháp với nhãn cần sửa.</p>
    </section>

    <?php if ($qlw_is_admin) : ?>
    <details class="qlw-card qlw-wide qlw-settings">
      <summary>Cài đặt nâng cao (quản trị viên)</summary>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="qlw_save_settings">
        <?php wp_nonce_field('qlw-settings'); ?>
        <fieldset>
          <legend>Nguồn tải model</legend>
          <label><input type="radio" name="model_source" value="huggingface" <?php checked($settings['model_source'], 'huggingface'); ?>> Hugging Face (mặc định, trình duyệt tải trực tiếp từ huggingface.co)</label><br>
          <label><input type="radio" name="model_source" value="self" <?php checked($settings['model_source'], 'self'); ?>> Tự lưu trữ trên website này</label>
          <p class="qlw-muted">Thư mục: <code><?php echo esc_html(\QLW\Plugin::self_hosted_dir() . \QLW\Plugin::MODEL_ID . '/'); ?></code></p>
          <ul class="qlw-files">
            <?php foreach (\QLW\Plugin::self_hosted_status() as $qlw_file => $qlw_exists) : ?>
              <li><span class="dashicons <?php echo $qlw_exists ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>" aria-hidden="true"></span> <code><?php echo esc_html($qlw_file); ?></code> <?php echo $qlw_exists ? 'có' : 'chưa có'; ?></li>
            <?php endforeach; ?>
          </ul>
          <p class="qlw-muted">Cần ít nhất config.json, generation_config.json, tokenizer.json, tokenizer_config.json và model_q4f16.onnx (WebGPU) hoặc model_q4.onnx (WASM), lấy đúng revision <code><?php echo esc_html(\QLW\Plugin::MODEL_REVISION); ?></code>.</p>
        </fieldset>
        <p><label><input type="checkbox" name="isolation" value="1" <?php checked($settings['isolation'], 1); ?>> Bật cross-origin isolation cho trang này (WASM đa luồng nhanh hơn). Tắt nếu plugin khác trên trang này bị lỗi hiển thị.</label></p>
        <?php submit_button('Lưu cài đặt', 'secondary', 'submit', false); ?>
      </form>
    </details>
    <?php endif; ?>
  </div>
</div>
