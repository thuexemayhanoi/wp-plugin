# Báo cáo tương thích

## Website mục tiêu được đọc, không thay đổi

Ngày kiểm tra: 12/10/2026. Read-only metadata ghi nhận:

- URL: https://blog.rentbikehanoi.com/
- WordPress 7.1.3, PHP 8.3.35.
- Theme Nguyễn Hà Glass 2026 v2.4.0.
- Có Yoast SEO, GetGenie, plugin AI, WP Agent, WPVibe, LiteSpeed và Hostinger.
- Tên website quan sát: Thuê xe máy Nguyễn Hà. Không tự áp Brand Profile Nguyễn Tú hay thương hiệu repo `/web` vào plugin.

Đã đọc `functions.php`, `single.php`, `header.php`, `footer.php`, `style.css` của active theme. `single.php` tạo H1 bằng `the_title()`, gọi TOC, share, post navigation, related posts và contact box. Plugin lưu post_title riêng, body chỉ H2/H3 và không chèn lại các thành phần đó. Theme gọi Yoast description filter khi thiếu meta, nên plugin ghi meta có sẵn để tránh dùng fallback.

Đây là **review source/interface**, chưa phải kiểm thử plugin đang chạy trong theme này trên website thật. Không cài/activate plugin, chỉnh theme hay đăng bài thử lên live.

## Môi trường kiểm thử cục bộ đã chạy

| Thành phần | Phiên bản / kết quả |
|---|---|
| WordPress | 7.1.3, kích hoạt plugin thật |
| PHP | 8.3.6, PHP CLI/server thật |
| DB | MariaDB 10.11.7, các bảng InnoDB thật |
| Action Scheduler | 4.2.0 đóng gói; initialization, scheduling và runner callback đã chạy |
| Yoast SEO | 28.6 active; đọc lại metadata thật sau giao bài |
| GetGenie | 4.5.6 active cùng plugin; các job/admin cùng hoạt động |
| Trình duyệt | Chromium headless shell 131; 390/768/1440 px |

Từng adapter API đã chạy qua mock HTTP có xác nhận request format, HTTPS/TLS, header auth, usage, catalog và error behavior. Chưa inference bằng tài khoản/khóa thật nên **không xác nhận quota, quyền model hoặc chất lượng bài AI thật** của người dùng.

GetGenie được kích hoạt cùng hệ thống để thử coexistence, không dùng API/license của GetGenie và không kiểm thử mọi tính năng GetGenie. Kích hoạt bằng CLI bỏ qua redirect onboarding của GetGenie; UI chính vẫn được tải trong admin thật.

## Chưa nghiệm thu

- Theme Nguyễn Hà + toàn bộ plugin/cache/security hiện tại trong một bản staging đầy đủ của website.
- Hostinger real cron, loopback, firewall/WAF, PHP timeout và công suất nhiều ngày ở production.
- Safari iPhone/iPad thật; kiểm thử viewport Chromium không thay thế kiểm thử thiết bị thật.
- WordPress multisite/network activation (network activation được chặn rõ ràng), PHP 8.1/8.2 và MySQL 8 theo ma trận riêng.
- Mọi model tùy biến hoặc provider có cấu hình quyền khác tài liệu tiêu chuẩn.
- Số lượng 100/300/500 bài thực sự đã được viết qua API tính phí; bản này kiểm tra cơ chế queue/batch và không giả nhận đã sản xuất các lô đó.

## Tránh xung đột

Không đổi theme hoặc CSS/JS frontend; không đăng ký TOC, author box, floating CTA, related posts hoặc schema mới. Các tài nguyên admin dùng handle `masf-admin`, chỉ enqueue trong màn hình plugin. Bảng/options/hooks dùng prefix `masf_`/namespace `MASF`. Action Scheduler được load theo version negotiation, không định nghĩa lại functions/classes hoặc xóa bảng dùng chung.
