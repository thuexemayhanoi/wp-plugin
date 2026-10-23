# Feature inventory — `thuexemayhanoi/wp-plugin` (nhánh `feat/qwen-local-no-paid-api`)

Cập nhật: 2026-10-23, Qwen Local Writer **0.3.0-beta**, Multi AI SEO Content Factory **1.2.0** (không sửa trong phiên này).

Cách đọc cột **Tested**:
- **REAL MODEL**: chạy Qwen3-0.6B thật (GitHub Actions).
- **REAL WP**: chạy trên WordPress thật dùng một lần (WP 6.9.10/7.1.3 + MySQL 8 trong CI; WP 7.1.3 + SQLite cục bộ).
- **E2E**: Chromium thật, model giả `fake-transformers.mjs` — chỉ kiểm thử hệ thống, không phải chất lượng Qwen.
- **UNIT**: kiểm thử ngoại tuyến, không WordPress.
- **MOCK**: WordPress bị giả lập bằng stub.
- **NONE**: chưa có kiểm thử.

**Status**:
- **DONE**: có mã và có bằng chứng.
- **PARTIAL**: thiếu một phần hoặc thiếu kiểm thử phần quan trọng.
- **NOT VERIFIED**: chưa có thiết bị hoặc môi trường để kiểm thử.
- **UI ONLY** / **BROKEN**: có giao diện nhưng không có backend, hoặc hỏng.

## A. Qwen Local Writer (`qwen-local-writer/`, 0.3.0-beta)

| Function | Implemented | Tested | Status | Evidence |
|---|---|---|---|---|
| Plugin độc lập, kích hoạt/huỷ kích hoạt giữ dữ liệu | yes | REAL WP | DONE | `qwen-local-writer.php`, `Plugin::activate/deactivate`; `wp-integration.php` (deactivate/reactivate) |
| Gỡ cài đặt chỉ xoá `qlw_settings`, `qlw_version` | yes | REAL WP (CI) | DONE | `uninstall.php`; CI bước "Uninstall keeps posts" |
| Nâng/hạ phiên bản bằng ZIP (0.3.0 ↔ 0.2.0), cập nhật `qlw_version` | yes | REAL WP | DONE | `Plugin::maybe_upgrade`; `tests/rollback.sh` (cục bộ PASS; CI chạy trong `qwen-wordpress`) |
| Qwen3-0.6B ONNX, revision ghim `58ca03a5…`, chỉ cho phép model này | yes | REAL MODEL | DONE | `Plugin::MODEL_*`, `qwen-worker.js` `ALLOWED_MODELS`; workflow `qwen-real-inference.yml` |
| Transformers.js 4.3.1 + ORT Web đóng gói, không CDN, kiểm tra sha512/sha256 | yes | UNIT + CI | DONE | `scripts/vendor_qwen_deps.py`, `assets/vendor/manifest.json`, `build_qwen.py` (từ chối chuỗi CDN/API) |
| Không dùng API AI thương mại, không API key | yes | REAL WP + E2E | DONE | quét ZIP; E2E "no plugin request left this WordPress site"; trình duyệt thật chỉ gọi huggingface.co, `*.hf.co`, gravatar |
| Phát hiện WebGPU thật/giả lập, shader-f16 → q4f16/q4 | yes | E2E + REAL MODEL (SwiftShader) | PARTIAL | `probe/candidates` trong worker; **GPU thật: NOT VERIFIED** |
| Dự phòng WASM đa luồng (COOP/COEP chỉ trên trang plugin) | yes | REAL MODEL (Chromium) + E2E | DONE | real browser 17,5–30,5 phút/bài; E2E kiểm tra header và `crossOriginIsolated` |
| Tải model, cache trình duyệt, tải lại từ cache | yes | REAL MODEL | DONE | real browser: tải lại 0,04–0,08 phút |
| Xoá cache model | yes | E2E (fake) | PARTIAL | `clearCache()`; xoá cache thật chưa đo |
| Giải phóng bộ nhớ (unload + terminate Worker), tải lại | yes (0.3.0) | E2E | DONE | nút `#qlw-unload`; E2E "Qwen loads again after freeing memory" |
| Viết theo phần: dàn ý → mở bài → 7 H2 (+H3) → kết luận, ~1.500 từ | yes | REAL MODEL | DONE | `writeArticle`; các lần chạy thật trong QWEN-TEST-REPORT: 1.403–1.806 từ (CPU), 1.599–1.776 từ (Chromium) |
| Làm sạch: lặp câu/đoạn, chữ Hán, câu chatbot/meta, mục trộn lẫn, phần bị cắt, heading rác, tiền tố từ khóa H2, dòng nhắc tiêu đề | yes | UNIT (từ đầu ra thật) + REAL MODEL | DONE | `cleanPart/parseOutline/dropRepeats`; `core.test.mjs` (29) |
| Prompt bài thuê xe: "THUÊ, không phải mua bán" | yes (0.3.0) | UNIT + REAL MODEL | PARTIAL | `contextBlock`; lần chạy 8–11: model 0.6B vẫn viết "người mua/mua xe" — QA đánh dấu để người biên tập sửa |
| Nút Dừng thật sự dừng, giữ bản nháp | yes | UNIT + E2E | DONE | `InterruptableStoppingCriteria`; E2E "stop keeps the partial draft" |
| Lưu khôi phục trong trình duyệt (cả khi đang stream), khôi phục sau tải lại | yes | E2E | DONE | `persist/persistThrottled`; E2E "reload during writing restores the streamed text" |
| Worker sập → báo đúng, giữ nội dung | yes (0.3.0) | E2E (crash giả lập) | DONE | mã `WORKER_CRASHED`; E2E "a crashed Worker is reported as a crash" |
| Mất mạng khi tải model / khi lưu | yes | E2E | DONE | `NETWORK`; E2E "network failure while downloading is explained" |
| Lưu **Nháp** (không bao giờ xuất bản) | yes | REAL WP + REAL MODEL | DONE | `save_draft` luôn `post_status=draft`; nháp #5 trong CI |
| Cập nhật đúng bản nháp, không tạo trùng; cảnh báo trùng tiêu đề | yes | REAL WP + E2E | DONE | `wp-integration.php`; E2E |
| Không ghi đè bài đã rời Nháp, bài không do plugin tạo, bài của người khác | yes (người khác: 0.3.0) | REAL WP | DONE | mã `not_draft`, 404, `not_owner` |
| Markdown → block H2–H3/đoạn/danh sách, không H1, escape, `wp_kses_post` | yes | UNIT + REAL WP + E2E | DONE | `markdown.php` (18); block editor xác nhận mọi block hợp lệ |
| Danh mục (kiểm tra `assign_term`) | yes | REAL WP | DONE | `wp-integration.php` |
| QA kỹ thuật (ngưỡng 70) | yes | UNIT + REAL MODEL | DONE | `quality.php` (67); bài thật QA 40–100 (lần 9 không đạt — đúng), lần 10–11 QA 75–100 |
| Kiểm chứng dữ kiện (giá lệch brief theo mẫu xe, xe xăng gọi là xe điện, địa lý Hà Nội, mua/thuê, không cần cọc, khẳng định, đánh giá, nguồn, năm, trải nghiệm) | yes (0.3.0) | UNIT (câu thật) + REAL MODEL (lần 8–11) | PARTIAL | lần 11 bắt được giá Vision/Wave tráo, giá và link bịa; chỉ bắt mẫu sai đã biết; không thay được người kiểm chứng |
| SEO: từ khóa, H2 nhồi từ khóa, độ dài tiêu đề, trùng tiêu đề/nội dung với bài đã có | yes (0.3.0) | UNIT + REAL WP | DONE | `Ajax::similar_posts`; tích hợp "copying an existing post is flagged" |
| Biên tập (con người): ô xác nhận trong trình soạn bài, tự bỏ khi Qwen viết lại | yes (0.3.0) | REAL WP + E2E (block editor) | DONE | `save_editorial_review`; E2E "editorial sign-off is saved from the block editor metabox" |
| Dữ kiện đã xác minh của cửa hàng + nút chèn vào brief | yes (0.3.0) | REAL WP + E2E | DONE | cài đặt `verified_facts`; E2E "insert button adds the verified facts" |
| Từ khóa chính Yoast SEO / Rank Math cho nháp mới (không ghi đè) | yes (0.3.0) | REAL WP + CI compat | DONE | `focus_keyword_keys`; CI nhận diện Yoast SEO 28.7 và Rank Math 1.0.280 thật (tích hợp 68/68); ghi meta và không ghi đè kiểm qua filter |
| Không tải mã Qwen ở frontend công khai | yes | REAL WP + E2E | DONE | tích hợp "public frontend loads no Qwen script"; E2E trang chủ |
| Quyền + nonce mọi AJAX/cài đặt; subscriber bị chặn; CSRF | yes | REAL WP + E2E | DONE | `Ajax::guard`; 403 cho nonce sai/thiếu/của người khác |
| Giao diện desktop/iPhone/iPad (bố cục) | yes | E2E (giả lập thiết bị) | PARTIAL | không tràn ngang, ô 16px; **Safari/iOS thật với model: NOT VERIFIED** |
| Tương thích WooCommerce, Yoast SEO, Rank Math | n/a | REAL WP (CI compat) | DONE | WooCommerce 11.2.1 + Yoast SEO 28.7 + Rank Math 1.0.280 cùng bật: tích hợp 68/68, E2E 59/59, rollback, gỡ cài đặt, không PHP Fatal (CI `97af80d`) |
| Tương thích plugin cũ 1.2.0 cùng kích hoạt | n/a | REAL WP | DONE | CI cài cả hai ZIP; rollback giữ trạng thái plugin cũ |
| Tự lưu trữ model cùng tên miền | yes | E2E (fake) | PARTIAL | đường dẫn và cấu hình đã kiểm; tải trọng số thật từ thư mục tự lưu trữ chưa thử |
| Viết hàng loạt / chạy nền máy chủ / tự xuất bản | no | — | NOT IMPLEMENTED (cố ý) | yêu cầu: chỉ nháp, con người biên tập |

## B. Multi AI SEO Content Factory 1.2.0 (thư mục gốc — giữ nguyên, không sửa)

Kiểm kê từ mã nguồn (file:line trong báo cáo kiểm kê của phiên này). Plugin này **gọi API trả phí** (Claude, Gemini, OpenAI).

| Function | Implemented | Tested | Status | Evidence |
|---|---|---|---|---|
| Menu quản trị (`masf`, 4 trang) | yes | CI kích hoạt | DONE | `includes/class-admin.php:18-23, 47-51` |
| Viết nhanh 1–100 bài, 1.500–10.000 từ, H2–H6, danh mục, nháp/xuất bản, tiêu đề hoặc Excel/CSV | yes | MOCK (`smoke-v120.php`) | DONE (mock) | `class-admin.php:289-322, 810-864` |
| Lưu API key AES-256-GCM bằng WP salts | yes | MOCK | DONE (mock) | `class-settings.php:77-111` |
| Provider HTTPS allowlist (api.anthropic.com, generativelanguage.googleapis.com, api.openai.com) | yes | NONE | PARTIAL | `includes/class-adapter.php:98-113`; không gọi API thật trong kiểm thử |
| Hàng đợi nền Action Scheduler, lease, checkpoint, retry/backoff, watchdog | yes | NONE | PARTIAL | `class-queue.php` |
| Nhập CSV/XLSX (5 MB, 10k dòng, chặn XXE) | yes | NONE | PARTIAL | `class-importer.php` |
| Markdown `_masf_markdown` → HTML lọc | yes | MOCK (kses giả) | PARTIAL | `class-markdown.php`; smoke stub `wp_kses` bằng `strip_tags` |
| Phân loại lỗi quota/billing, dừng campaign | yes | MOCK | DONE (mock) | `includes/class-adapter.php:59-86` |
| Trang Cài đặt/Logs/Queue/QA/Schedule, xuất CSV/JSON, hồ sơ thương hiệu | yes (mã) | NONE | UI ONLY / UNREACHABLE | các trang không còn đăng ký menu từ 1.2.0 (`class-admin.php:259-266`) |
| Trường giới hạn rpm, token/ngày, giá, ngân sách | partial | NONE | BROKEN | không có ô nhập; ngân sách > 0 luôn tạm dừng campaign (`includes/class-providers.php:114`) |
| Giới hạn số từ trong form campaign (1000–5000) khác máy chủ (1500–10000) | inconsistent | NONE | BUG (ghi nhận, chưa sửa) | `class-admin.php:437`, `class-settings.php:137` |
| Gỡ cài đặt không xoá bảng/tùy chọn | yes (cố ý) | NONE | DONE | `uninstall.php` |
| `tests/smoke-v110.php` | — | FAIL | OBSOLETE | kiểm thử giao diện 1.1 đã bị thay ở 1.2.0; không chạy trong CI |

## C. Công cụ và CI

| Function | Status | Evidence |
|---|---|---|
| `ci.yml` › `lint-and-build` (PHP 8.1/8.3): `php -l`, QA/Markdown/JS tests, vendor check, build 2 ZIP, `smoke-v120.php` | DONE | `.github/workflows/ci.yml` |
| `ci.yml` › `qwen-wordpress`: WP 6.9.10/PHP 8.1, WP 7.1.3/PHP 8.3, WP 7.1.3 + WooCommerce/Yoast/Rank Math: tích hợp, E2E, rollback, gỡ cài đặt, quét lỗi PHP | DONE (job compat mới ở 0.3.0) | `.github/workflows/ci.yml` |
| `qwen-real-inference.yml`: 4 bài Node CPU + 1 bài Chromium/WASM lưu nháp WordPress | DONE | `.github/workflows/qwen-real-inference.yml` |
| `build_qwen.py` (ZIP xác định, kiểm tra version, chặn CDN/API) · `build_plugin.py` (ZIP plugin cũ, không xác định) | DONE · PARTIAL | `scripts/` |
| Model / thư viện / giấy phép | — | Qwen3-0.6B Apache-2.0; Transformers.js Apache-2.0; ONNX Runtime Web MIT; plugin GPL-3.0-or-later; Action Scheduler GPL-3.0 |
| Secrets trong repo | không có | chỉ mật khẩu CI dùng một lần và chuỗi giả trong test |
