# Multi AI SEO Content Factory (WordPress)

**Current version: 1.1.0 — developer source repository.** Tạo bài viết SEO bằng API Claude, Gemini hoặc OpenAI ngay trong WordPress. Giao diện **AI Content Factory → Viết bài nhanh** hỗ trợ từ **1 đến 100 bài**, chọn danh mục, dán tiêu đề hoặc nhập CSV/Excel `.xlsx`, đặt mục tiêu **1.000–5.000 từ/bài** và trần output **4k–64k token** tùy model.

> **Không phải chatbot.** Repository này đã thay thế mã QuickCall Connect cũ. Lịch sử Git cũ vẫn có thể xem để khôi phục, nhưng không còn mã chatbot trong cây nguồn hiện tại.

## Cài đặt ZIP

1. Vào tab **Actions → CI & Build WordPress ZIP**, chọn run thành công rồi tải artifact `multi-ai-seo-content-factory-wordpress-zip`.
2. Giải nén artifact tải từ GitHub Actions **một lần** để lấy đúng file `multi-ai-seo-content-factory.zip`.
3. WordPress Admin → **Plugins → Add New → Upload Plugin** → chọn `multi-ai-seo-content-factory.zip` → Install/Replace → Activate.
4. Vào **AI Content Factory → Viết bài nhanh**; nhập API key và Model ID được cấp quyền; bắt đầu với 1–5 bài **Draft**. Xem [hướng dẫn tiếng Việt](QUICK-START-VI.md).

### Lưu ý khi sử dụng

- Khóa API được mã hóa phía máy chủ; không commit khóa vào repo, không gửi key lên issue/PR. ChatGPT Plus/Claude/Gemini subscription không thay thế API credit.
- Plugin phân biệt 429 do **rate limit** (retry) và một số lỗi **insufficient quota/billing** (tạm dừng). Không hiển thị số dư tài khoản API, phải kiểm tra trong cổng thanh toán nhà cung cấp.
- **4.000 output token không đảm bảo bài 5.000 từ**. Trần output phụ thuộc model, request, ngân sách và giới hạn nhà cung cấp; mặc định 16.000, cần Test Connection.
- Action Scheduler 4.2.0 được đóng gói trong source, chạy qua WP-Cron/cron server; không bắt buộc để admin mở trang.
- Draft mặc định. Luôn rà soát factuality, copyright, SEO, NAP, nội dung AI và chi phí trước khi xuất bản. Không chạy 100 bài trên production khi chưa nghiệm thu thử 1–5 bài.

## Mã nguồn và phát triển

- Điểm vào WordPress: [`multi-ai-seo-content-factory.php`](multi-ai-seo-content-factory.php).
- Giao diện/tạo chiến dịch: [`includes/class-admin.php`](includes/class-admin.php).
- API adapters: `includes/class-claude.php`, `class-gemini.php`, `class-openai.php`, `class-adapter.php`, `class-api-error.php`.
- Hàng đợi/checkpoint, QA: `includes/class-queue.php`, `class-engine.php`, `class-qa.php`, `class-store.php`.
- Excel/CSV: `includes/class-importer.php`.
- JavaScript/CSS admin: `assets/`.
- Thư viện phụ thuộc bundled: `vendor/action-scheduler` (giữ nguyên license và nguồn gốc thư viện).
- Smoke test giả lập: `php tests/smoke-v110.php`; PHP lint `find . -name '*.php' -print0 | xargs -0 -n1 php -l`.
- Build ZIP local (Python 3.10+): `python scripts/build_plugin.py`; output `dist/multi-ai-seo-content-factory.zip`.

### Chạy CI và build

`.github/workflows/ci.yml` chạy PHP syntax + mock smoke test, kiểm tra cấu trúc ZIP, tải artifact và phát hành GitHub Release khi push Git tag `vX.Y.Z` khớp phiên bản đã kiểm thử. **CI không chạy API tính phí hoặc WordPress/Hostinger live**. Report lịch sử bản 1.0.0 và test giả lập bản 1.1.0 ở [`TEST-REPORT.md`](TEST-REPORT.md), [`TEST-REPORT-V110.md`](TEST-REPORT-V110.md). Các tuyên bố kiểm thử cần đọc đúng phạm vi.

## Tài liệu

- [AGENTS.md](AGENTS.md) — quy tắc cho ChatGPT/Claude/Gemini/coding agents.
- [QUICK-START-VI.md](QUICK-START-VI.md) — dùng màn hình viết nhanh.
- [INSTALLATION.md](INSTALLATION.md) — cài, cập nhật, cron và yêu cầu hosting.
- [CONFIGURATION.md](CONFIGURATION.md) — API/providers và bảo mật.
- [WORKFLOW.md](WORKFLOW.md) — Matrix, queue, QA, retry, checkpoints.
- [COMPATIBILITY.md](COMPATIBILITY.md) / [RECOVERY.md](RECOVERY.md) — vận hành và phục hồi.
- [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) / [docs/RELEASE.md](docs/RELEASE.md) — dev và release.
- [CHANGELOG.md](CHANGELOG.md) — lịch sử nâng cấp.

## Tương thích và kiểm thử

WordPress 6.9+, PHP 8.1+, MySQL/MariaDB, `mbstring`, `dom`, `openssl`, và `zip` + `simplexml` để nhập Excel. Phiên bản **1.1.0 chưa được xác nhận bằng API key thật hoặc production**; tài liệu 1.0.0 chứa kết quả tích hợp cục bộ trước đó.

**License: GPL-3.0-or-later.** Action Scheduler và các thư viện đi kèm giữ giấy phép riêng trong `vendor`.
