# Changelog

## Qwen Local Writer 0.2.0-beta — 2026-10-23 (plugin riêng trong `qwen-local-writer/`)
- Bỏ import thư viện từ CDN: Transformers.js 4.3.1 + ONNX Runtime Web 1.31.0-dev được đóng gói trong ZIP, lấy từ npm và kiểm tra sha512/sha256 (`scripts/vendor_qwen_deps.py`, `assets/vendor/manifest.json`).
- Ghim model `onnx-community/Qwen3-0.6B-ONNX` revision `58ca03a5e7b354b480b1a82b34bde8dc7b854cc0`; chỉ cho phép model này.
- Worker mới: phát hiện GPU thật/giả lập và `shader-f16` (q4f16/q4), dự phòng WASM đa luồng (COOP/COEP chỉ trên trang plugin), streaming, dừng ngay, tắt chế độ thinking của Qwen3, thông báo lỗi chính xác (mạng, 404, RAM, dung lượng, GPU ngắt).
- Viết theo dàn ý 7 H2 + mở bài + kết luận (~1.500 từ), tự điều chỉnh độ dài, viết lại/bổ sung khi đầu ra yếu, làm sạch `<think>`, lời dẫn, H1/H2, chữ Hán.
- Lưu nháp an toàn: block Gutenberg H2–H3, kiểm tra trùng tiêu đề, cập nhật đúng bản nháp thay vì tạo bài trùng, không ghi đè bài đã rời trạng thái Nháp, kiểm tra quyền gán danh mục, hộp Markdown & QA trong editor.
- QA kỹ thuật 70/100 mở rộng: sai ngôn ngữ, chữ Hán, câu lặp, phần quá mỏng, bịa trải nghiệm, giá/số điện thoại/%/link không có trong brief.
- Giao diện mới đáp ứng desktop/iPhone/iPad, kiểm tra thiết bị, tiến độ tải/viết, khôi phục bản đang soạn, xoá cache model, cài đặt tự lưu trữ model.
- Kiểm thử: PHP unit, node:test, tích hợp WordPress thật (vai trò, nonce, nháp, kses, huỷ kích hoạt/gỡ), E2E Chromium; CI chạy suy luận Qwen thật VI/EN.

## 1.2.0 — 2026-10-12
- Rút gọn trang Viết bài nhanh và Cài đặt API: chỉ chọn AI đã lưu, tiêu đề / Excel, 1–100 bài, 1.500–10.000 từ, danh mục cha/con, nháp hoặc chủ động xuất bản.
- Chọn mục tiêu số thẻ H2, H3, H4, H5, H6; không sinh H1 vì WordPress dùng tiêu đề bài.
- Workflow đơn giản bỏ vòng QA/reviewer; vẫn giữ lọc Markdown/HTML, quyền hạn, checkpoint, retry và giới hạn API. Workflow cũ vẫn tương thích.
- Markdown được lưu trong post meta `_masf_markdown`, chuyển thành HTML an toàn và có hộp xem nguồn tại WordPress editor.
- Kiểm thử mock mới `tests/smoke-v120.php`, GitHub Actions lint + build ZIP.
- Giới hạn: số từ/số heading là mục tiêu theo prompt, không có xác nhận API thực tế; bài rất dài có thể thất bại do giới hạn model.

# Changelog

## 1.1.0 — 2026-10-12

- Added one-page **Viết bài nhanh**: provider/key/model, count 1–100, category, target 1,000–5,000 words, language, selectable 4k–64k output tokens, titles or Excel/CSV, queue start and Draft-only default.
- API key status and local show/hide typed key; previously saved key remains server-side only.
- Provider-specific quick model persistence without changing other campaign model choices. Per-campaign token output limit instead of globally overriding existing campaigns.
- Classify provider HTTP errors: rate limit vs insufficient credits/spending quota; prevent cascading failures by pausing campaign on non-retryable billing/auth/model errors; clearer recent FAILED diagnostics.
- Increased advanced provider output-token configuration ceiling from 32,000 to 64,000 (model-dependent).
- No change to table schema, post URLs, generated posts or existing campaign checkpoint logic; preserved existing advanced menus.

## 1.0.0

Initial engine, scheduler, Matrix, QA, provider adapters and CSV/XLSX import.
