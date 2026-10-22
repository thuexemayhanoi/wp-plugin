# Qwen Local Writer — No Commercial AI API (Beta Branch)

> **New development path:** [Qwen Local Writer source](qwen-local-writer/README.md) | [Architecture spec](docs/superpowers/specs/2026-10-23-qwen-local-writer-design.md) | [PR #1](https://github.com/thuexemayhanoi/wp-plugin/pull/1)
>
> The **separate `qwen-local-writer/` WordPress plugin** is a no-paid-API, WebGPU browser-local Qwen3-0.6B draft editor aiming for ~1,500 words and a **technical** QA threshold of 70/100. It writes WordPress **drafts only**; no paid Claude/Gemini/OpenAI inference, no API keys, no hidden PHP model backend.
>
> **Beta warning:** Syntax/QA/build CI passing does not mean actual WebGPU inference or WordPress staging activation has been verified. Qwen can hallucinate and may miss 1,500 words; browser must stay open; initial JS library uses a pinned CDN module requiring vendoring and security review before production. Do **not** deploy to the active website yet.
>
> **Legacy preservation:** The older API-driven plugin **below** remains unchanged in this repository to protect existing campaigns and database state. The standalone Qwen ZIP contains none of the commercial-provider PHP classes. No destructive migration or automated removal of existing credentials has taken place.

---

## Legacy v1.2 plugin documentation (preserved)

# Multi AI SEO Content Factory — WordPress v1.2.0

**Plugin tạo bài Markdown đơn giản**, hỗ trợ API Claude, Gemini, OpenAI; quản lý source chính tại repo `thuexemayhanoi/wp-plugin`.

## Dùng trong WordPress (giao diện tiếng Việt)

1. **AI Content Factory → Cài đặt API**: nhập API key và Model ID hợp lệ của một nhà cung cấp, bấm **Lưu API & Model**. Key được mã hóa AES-256-GCM bằng salt của WordPress; để trống khi đã lưu. Không có key trong repo/ZIP; plugin không biết trực tiếp số dư tiền.
2. **AI Content Factory → Viết bài nhanh**: chọn AI, số bài **1–100**, mục tiêu từ **1.500–10.000 từ**, ngôn ngữ, danh mục cha/con, **Nháp** hoặc **Xuất bản sau khi viết**, số lượng H2/H3/H4/H5/H6.
3. Nhập tiêu đề, mỗi dòng một tiêu đề, hoặc Excel `.xlsx`/CSV cột `title`. Xác nhận chi phí API rồi bấm **Bắt đầu viết bài**.
4. Xem tiến độ trong **Viết bài nhanh** hoặc **Chiến dịch viết bài**. Mở bài ở mục **Bài viết** WordPress, nội dung Markdown được chuyển an toàn thành HTML và bản nguồn nằm trong hộp **Markdown gốc của bài AI** dưới trình sửa bài.

**Không có vòng QA/reviewer cho chiến dịch mới chế độ đơn giản.** An toàn kỹ thuật (nonce/quyền admin, lọc HTML, giới hạn request, trạng thái, lưu nháp/checkpoint, xử lý lỗi/quota) vẫn bắt buộc. API có thể trả nội dung ngắn hơn mục tiêu hoặc sai số lượng heading; phải tự kiểm tra khi xuất bản. Model khác nhau có giới hạn output; bài 10.000 từ không được bảo đảm trong một lượt API.

## Phát triển và đóng gói

- `multi-ai-seo-content-factory.php`: điểm vào WordPress; `includes/class-admin.php`: giao diện;
- `includes/class-engine.php`: engine; `includes/class-markdown.php`: chuyển Markdown an toàn và hiển thị nguồn trong editor;
- `includes/class-{openai,claude,gemini,adapter,providers,settings}.php`: các API;
- `includes/class-{queue,store,importer}.php`: hàng đợi, checkpoint, Excel/CSV;
- `vendor/action-scheduler/`: thư viện chạy nền (giữ giấy phép kèm theo);
- `.github/workflows/ci.yml`: PHP 8.1/8.3 lint, smoke tests và ZIP artifact; tag `v1.2.0` tạo Release nếu CI đạt.

Cục bộ: `php tests/smoke-v120.php && python3 scripts/build_plugin.py`. ZIP: `dist/multi-ai-seo-content-factory.zip` (thư mục gốc chính xác); không đóng gói tài liệu phát triển hoặc test.

## An toàn và giới hạn

- Chưa xác minh bằng API key thật hoặc WordPress production. Bản test mới chỉ là mock/lint/package; không khẳng định đã sửa được mọi lỗi runtime.
- Khóa API không xuất ra HTML, JS, log hoặc GitHub. Cẩn trọng khi chia sẻ database backup.
- Không xóa bảng/bài đang dùng khi cập nhật. Bài từ workflow cũ vẫn giữ đường chạy cũ khi có.
- Nên chạy 1 bài nháp trước, sau đó 5 bài, rồi mới tăng lên 100 bài. Kiểm tra lỗi billing/quota tại trang tài khoản API.

Đọc thêm: [AGENTS.md](AGENTS.md), [CHANGELOG.md](CHANGELOG.md), [QUICK-START-VI.md](QUICK-START-VI.md), [SECURITY.md](SECURITY.md).
