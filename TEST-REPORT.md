# Báo cáo kiểm thử 1.0.0

Ngày hoàn tất: 12/10/2026. Kiểm thử chỉ chạy trong WordPress cục bộ, không cài hoặc đăng bài thử lên `blog.rentbikehanoi.com`. API thật: **0 cuộc gọi**; không sử dụng/gửi yêu cầu khóa thật trong chat.

## Kết quả thực sự đã chạy

| Nhóm | Kết quả | Bằng chứng/phạm vi |
|---|---|---|
| Integration WordPress/PHP/MariaDB | **88/88 PASS** | WP 7.1.3, PHP 8.3.6, MariaDB 10.11.7/InnoDB, Yoast 28.6, GetGenie 4.5.6, AS 4.2.0 active |
| HTTP admin | **21/21 PASS** | Đăng nhập cục bộ, form nonce, tạo campaign, HTTP upload Excel thật, import, Matrix, REST auth, CSRF, CSV/JSON downloads, chín màn hình |
| Browser/UI | **14/14 PASS** | Chromium headless shell 131, viewport 390/768/1440, form không tràn, không lỗi JS, upload/import/REST/download hoạt động |
| Cú pháp PHP | **121 file PASS** | PHP chính, includes, uninstall và toàn bộ thư viện vendored trong gói source |
| Cú pháp JavaScript | **PASS** | `node --check assets/admin.js` |
| WordPress Coding Standards | **0 errors / 0 warnings** | PHPCS 3.13.3 + WordPressCS 3.4.1, source plugin riêng; vendor không sửa/lint theo ruleset này |
| Native ZIP installation | **PASS** | `Plugin_Upgrader::install()` thật trong WP cục bộ, kiểm tra plugin name/version/requirements, kích hoạt lại từ thư mục ZIP đã giải nén |
| Package inspection | **PASS** | Một thư mục gốc, PHP header đúng, ZIP đọc/giải nén được; source khớp checksum, không có key thật hoặc DB/user settings |

Các annotation PHPCS được dùng có lý do cụ thể: truy vấn bảng riêng cần dữ liệu mới cho lease/quota, tên bảng từ prefix/constant tin cậy và giá trị dùng prepared queries; đọc GET chỉ lọc báo cáo sau capability check; PHP-uploaded temp file được importer xác thực; stream CSV; Base64 ciphertext; property DOM/ZipArchive native; exception text được escape tại HTML/JSON boundary. Không vô hiệu hóa nonce/capability checks thực tế.

## Các ca tích hợp chính

- Kích hoạt plugin, tạo bảng, initialization Action Scheduler và chạy callback bằng **runner thật**, không cần Dashboard.
- Nhập 5 tiêu đề, xếp queue, viết 5 Draft, lưu post ID trước QA, Pause/Resume giữ checkpoint, giao Draft sau QA.
- Excel tiếng Việt/namespace XML, CSV BOM/delimiter/quoted multiline; import lặp không thêm bài; từ chối `.xls`, CSV lỗi encoding, Excel có công thức.
- Ba adapter: catalog, request format/endpoint/HTTPS/TLS/no redirects, auth header, JSON content parsing và token usage.
- Yoast title/focus keyword/primary category được đọc lại từ post meta thật.
- Pending, Publish và Future/Schedule với event `publish_future_post`; publication cập nhật Matrix.
- Writer A/reviewer B; QA failure → REPAIR dùng provider B → QA → một Draft; hết số lần sửa → BLOCKED giữ Draft.
- Lỗi auth dùng fallback đã chọn; 429/Retry-After/backoff; timeout giữ dự phòng ngân sách; response truncated không publish nhưng giữ usage.
- Ngân sách và hạn bài/ngày chặn request kế tiếp; manual batch giao đúng 5, bài thứ 6 PLANNED.
- UUID/title hash/replay, lease độc quyền/fencing, worker hết hạn, checkpoint cũ bị từ chối.
- Phục hồi Draft đã insert nhưng chưa checkpoint post ID, không insert bài thứ hai.
- Retry 429 lặp chạm giới hạn và FAILED, không chạy vô hạn.
- Script/H1/shortcode nguy hiểm, placeholder/ngắn, keyword stuffing, missing facts, tiền chưa xác minh, link không thuộc nguồn, internal target thật và link nội bộ hỏng.
- Key encrypted roundtrip, DB option không autoload, API key không xuất HTML, anonymous/subscriber bị từ chối, deactivation giữ posts/settings/Matrix.

Mock tạo nội dung fixture để kiểm tra pipeline; **đây không phải kiểm chứng chất lượng SEO của bài AI thật**. Không công bố điểm QA như chứng nhận nội dung đạt top Google.

## Chưa chạy hoặc không được nhận là PASS

- Claude/Gemini/OpenAI inference bằng key/hạn mức thật và model tài khoản người dùng.
- Hosting Hostinger thật, cron/loopback/WAF/timeout production hoặc chiến dịch API tính phí 100–300 bài.
- Plugin active trong bản sao đầy đủ theme Nguyễn Hà Glass cùng toàn bộ cache/security plugin trên live. Theme được review source chỉ đọc; compatibility interface không phải production acceptance.
- Safari/iOS thiết bị thật; viewport test là Chromium.
- Multisite/network activation, các PHP/database/version khác trong ma trận riêng.
- Kiểm chứng từng mệnh đề thực tế, pháp luật, chống đạo văn toàn Internet hoặc chất lượng ngôn ngữ của tất cả model.
- Stress test nhiều worker thực sự chạy song song qua API thật trong nhiều ngày. Lease đã kiểm tra loại trừ/fencing/recovery ở DB; không suy rộng thành một tải production đã nghiệm thu.

Thử SQLite ban đầu đã giúp tìm/sửa lỗi native schedule và Excel namespace; môi trường SQLite có lỗi thư viện DB/Action Scheduler và không được dùng làm bằng chứng nghiệm thu cuối. Kết quả cuối dựa trên MariaDB/InnoDB.

Lỗi đã sửa trong quá trình QA: chọn namespace worksheet, date của draft (`edit_date`), thứ tự cập nhật batch counter MySQL, API key/UI nonce kiểm thử, shortcode động, và style/documentation của source. Xem `docs/test-evidence.json` để đọc các kết quả có cấu trúc, không chứa khóa/request body.
