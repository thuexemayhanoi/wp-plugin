# Kiểm thử nâng cấp v1.1.0 — 12/10/2026

- **PHP syntax**: kiểm tra tất cả file `.php` trong plugin bằng `php -l` (PHP 8.4 CLI).
- **JS syntax**: `node --check assets/admin.js` (Node 22).
- **Mock smoke tests**: `tests/smoke-v110.php` mô phỏng WordPress (không thật), **23/23 PASS**: tạo chiến dịch 5/100 bài, Draft-only, output token từng campaign, giới hạn số từ, lưu key, từ chối count 101/thiếu tiêu đề/key sai, lỗi 429 quota vs rate, Gemini 402, Claude 400 spend limit, 401 che message key.
- **ZIP**: một thư mục gốc, ZIP integrity và checksum được kiểm tra khi đóng gói.
- **Chưa test**: WordPress thật / Hostinger / database thật / Action Scheduler thật với v1.1.0; không gọi API Claude/Gemini/OpenAI bằng key thật; chưa xác nhận lỗi 12 FAILED của website cụ thể; chưa thực thi tạo 100 bài thật; chưa kiểm thử thiết bị iOS thật.
- **Lưu ý**: Kết quả 88/88, 21/21, 14/14 trong TEST-REPORT.md thuộc phiên **v1.0.0**, không được coi là kết quả regression của bản nâng cấp này.

## Các lưu ý kiểm thử production

1. Sao lưu WordPress DB và plugin v1.0.0. Cài cập nhật, kiểm tra giữ nguyên Matrix, bài cũ và API settings.
2. Test một bài với key/model thật để phát hiện sai output-token cap/model; kiểm tra WordPress Draft & Yoast.
3. Sau đó thử 5 bài; kiểm tra chạy WP-Cron/Action Scheduler và prompt, QA theo tài khoản thật.
4. Cuối cùng mới chạy 20–100 bài sau khi đã kiểm tra chi phí và dữ liệu thương hiệu.
