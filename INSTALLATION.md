# Cài đặt trên iPhone, iPad hoặc máy tính

## Cài ZIP từ iPhone

1. Bấm tải **multi-ai-seo-content-factory.zip**. Trong ứng dụng Tệp/Files, giữ nguyên file `.zip`, không chọn thư mục đã giải nén.
2. Mở Safari vào `https://blog.rentbikehanoi.com/wp-admin/` và đăng nhập tài khoản quản trị.
3. Mở menu **Plugins → Add New Plugin → Upload Plugin**. Nếu không thấy nút Upload, trong Safari chọn **aA → Request Desktop Website** rồi mở lại trang.
4. Bấm **Choose File → Browse**, chọn ZIP trong Downloads hoặc thư mục vừa lưu.
5. Bấm **Install Now**, đợi hoàn tất, rồi **Activate Plugin**.
6. Menu mới **AI Content Factory** xuất hiện ở thanh quản trị. Mở **API Providers** để cấu hình.

File ZIP có đúng một thư mục gốc `multi-ai-seo-content-factory/`, PHP chính nằm ngay bên trong. Source code đầy đủ nằm trong ZIP; không cần Composer, Node hay terminal để cài.

## Trước chiến dịch đầu tiên

- Xác nhận WordPress 6.9 trở lên, PHP 8.1 trở lên; website mục tiêu đã ghi nhận WordPress 7.1.3 / PHP 8.3.35 ở lần đọc hệ thống.
- Chọn Draft trước. Tự đăng chưa được bật mặc định.
- Nhập Brand Profile đúng của **website đang cài plugin**; mẫu không có tên, địa chỉ, giá hay chính sách điền sẵn.
- Nhập khóa tại API Providers, không gửi khóa vào chat. Nếu thay WordPress security salts hoặc chuyển website, có thể cần nhập lại khóa.
- Trên Hostinger: nếu Excel báo thiếu Zip, mở phần PHP extensions của đúng website để bật `zip` và `simplexml`; có thể dùng CSV ngay nếu chưa bật.

## Cron tùy chọn trên Hostinger

Mặc định plugin tạo một recurring action mỗi phút. Action Scheduler dùng WP-Cron/loopback của WordPress. Dashboard không phải worker.

Nếu website ít lượt truy cập, có thể nhờ Hostinger Agent tạo cron **chỉ cho website này**, gọi `wp-cron.php` mỗi 5 phút. Không cần đổi DNS, nameserver hoặc `.htaccess`. Lệnh minh họa cho Hostinger hỗ trợ `curl`:

```sh
curl --fail --silent --show-error --max-time 60 'https://blog.rentbikehanoi.com/wp-cron.php?doing_wp_cron' >/dev/null
```

Lịch cron minh họa: `*/5 * * * *`. Nếu hosting cho chạy PHP CLI thì có thể dùng đường dẫn `wp-cron.php` và PHP executable mà Hostinger xác nhận. Không dùng đường dẫn thư mục hosting đoán trước. Không phải tắt WP-Cron trong `wp-config.php` để dùng plugin; chỉ làm việc đó sau khi cron thật đã được xác minh và có sao lưu.

Trong **Tools → Scheduled Actions** có thể xem các action nhóm `masf-content-factory`. Plugin chỉ hủy action riêng của mình khi deactivation, không hủy công việc của WooCommerce/GetGenie/plugin khác.

## Vô hiệu hóa và gỡ bỏ

Deactivation đặt mọi chiến dịch đang chạy thành PAUSED, hủy các action còn chờ của plugin và không xóa bài, Matrix, khóa hoặc cài đặt. Bước đang gửi API có thể hoàn tất và giữ checkpoint/bản nháp; không tự đăng sau khi deactivation được nhận biết.

Gỡ file plugin không xóa dữ liệu tự động. Cài lại ZIP giữ lại các bảng/cài đặt cũ. Không có chức năng xóa hàng loạt dữ liệu hay bài đã đăng trong bản đầu.
