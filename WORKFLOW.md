# Workflow và kiến trúc

## Vận hành

`PLANNED → WRITING → QA → READY → PUBLISHED`

QA không đạt: `QA → REPAIR → QA`; hết số lần sửa: `BLOCKED`. Lỗi API không thể phục hồi hoặc vượt retry: `FAILED`. READY có thể là Draft/Pending đã giao, bài Future đã lên lịch hoặc bài đang chờ bước giao. `delivery_status` và `completed_at` phân biệt các trường hợp này. Bài Future đổi thành PUBLISHED khi WordPress thực sự đăng.

Mỗi action `masf_step` thực hiện một bước: viết/sửa (một request), reviewer (một request), local QA hoặc giao bài. Dispatcher mỗi phút chọn công việc đủ hạn và dùng Action Scheduler để chạy nền. Không có daemon hoặc vòng PHP chạy mãi. Lô lớn không đổi timeout của một action.

Pause/Stop giữ Matrix/content/QA/post ID. Request đã gửi không thể thu hồi khỏi nhà cung cấp; nó có thể trả nội dung và lưu checkpoint/bản nháp. Trước giao bài, plugin đọc lại trạng thái chiến dịch/quyền tác giả/plugin enabled. Publication đã bắt đầu sát thời điểm Pause có thể đã hoàn tất; Pause không thu hồi bài đã đăng.

## Lưu trữ

| Bảng | Vai trò |
|---|---|
| `wp_masf_campaigns` | Cấu hình chiến dịch, tác giả, trạng thái và bộ đếm batch |
| `wp_masf_articles` | Matrix, UUID/title hash, nội dung, phase, QA, post ID, lease/checkpoint |
| `wp_masf_requests` | Usage token/chi phí/dự phòng, provider/model, request ID và HTTP status |
| `wp_masf_locks` | Lease chống worker trùng, quota/dispatcher/publish mutex |
| `wp_masf_logs` | Nhật ký giới hạn độ dài, không lưu prompt/header/key |

Tên prefix được lấy từ `$wpdb->prefix`, không giả định luôn là `wp_`. Phiên bản đầu dành cho single-site; network activation/multisite chưa nghiệm thu.

## Chống trùng và phục hồi

- UUID article duy nhất; title hash unique toàn Matrix, kiểm tra tiêu đề tồn tại trong WordPress trước import và trước giao.
- Worker dùng compare-and-update lease token; checkpoint của worker cũ không ghi đè worker mới.
- Mỗi bài được tạo Draft với bootstrap slug `masf-{UUID}`. Nếu gián đoạn sau `wp_insert_post` nhưng trước lưu post ID, lần sau tìm lại bootstrap slug rồi lưu checkpoint, không insert thêm.
- Sau khi post ID đã checkpoint, giao bài mới đặt slug cuối cùng. Post meta `_masf_article_uuid` xác nhận quyền sở hữu.
- Bài publish/future được ghi nhận bằng native `transition_post_status`, giúp thu hẹp khoảng gián đoạn giữa WordPress và Matrix.
- Delivered Draft/Pending/Published/Future có completed checkpoint và không tự được retry. Post bị xóa/trash hoặc mất quyền sở hữu không tự tạo thay thế.
- 429/5xx/timeout retry có backoff, jitter và Retry-After; giới hạn cấu hình mặc định 4 lần. Phục hồi lease quá hạn tăng retry count, không chạy vô hạn.

Khoảng timeout có thể khiến nhà cung cấp tính phí hai lần nếu một request đã xử lý nhưng phản hồi bị mất và lần sau được gửi lại. Bảo vệ idempotency ở đây bảo đảm thao tác tạo bài WordPress, không tuyên bố mọi nhà cung cấp hỗ trợ idempotency generation.

## QA

Technical: HTML fragment, không script/event handler/H1/theme shell, UTF-8, category, slug, liên kết nội bộ, nguồn ngoài đã nhập. External link dùng WordPress safe HTTP HEAD tối đa 5 URL/request, timeout 3 giây, chặn SSRF/private network theo cơ chế WP. HEAD 403/timeout là chưa xác minh, không được báo link đã xác minh.

SEO: title/slug trùng, H2/H3, metadata, keyword use/stuffing, target links, cảnh báo cùng primary keyword. Độ dài title/meta là khuyến nghị, không hard fail đơn thuần vì ngoài khoảng.

Content: word range, paragraph lặp, placeholder, missing_data, số tiền/số điện thoại không khớp dữ liệu, claims không có đoạn bằng chứng đã nhập. Reviewer tùy chọn đánh giá ý định/nội dung/nhất quán. Dữ liệu nguồn người quản trị nhập vẫn phải đúng; không thể dùng thuật toán cục bộ bảo đảm không có thông tin bịa trong mọi câu.

QA hard errors luôn chặn delivery dù score vượt threshold. Score hỗ trợ quyết định, không bảo đảm chất lượng hay thứ hạng. Giá/hours/địa chỉ/pháp luật diễn đạt khác có thể cần review thủ công; chưa có một máy kiểm chứng ngữ nghĩa toàn diện.

## WordPress và theme

Plugin dùng `wp_insert_post`, `wp_update_post`, taxonomy, post meta, future post hooks, WordPress HTTP/nonce/capabilities và Action Scheduler. Không chèn header/footer/H1/TOC/related/CTA/schema/CSS vào bài; theme quản lý các thành phần đó. Không thay URL, canonical, metadata của bài cũ. Không đọc API key của GetGenie/AI plugin khác.

Yoast active: ghi title/metadesc/focuskw qua `WPSEO_Meta`, primary category qua meta Yoast. Yoast inactive: giữ SEO data riêng `_masf_seo_*`, không tự phát meta tag hoặc schema. Cần Yoast để hiển thị các metadata này qua cơ chế Yoast.

## Từ workflow `/web` sang WordPress

Đã đọc README, WRITER, factory script, config/state/queue/Matrix CSV và workflow tại main HEAD `39e939cf85658f82971c9c346d265b6d361fc780`. Snapshot đọc có 1.000 PUBLISHED, queue PAUSED/target reached, batch 10, repair tối đa 2; workflow concurrency group và watchdog, kiểm tra trùng, rebuild nếu main thay đổi trong publish.

Plugin chuyển ý tưởng Matrix/queue/QA/repair/checkpoint sang PHP và bảng DB. WordPress native storage/publishing thay Git commit/sitemap generator. Không sao chép template Node, nội dung, NAP, URL hay domain của repo. Repo được đọc qua raw URLs, không clone/push/deploy hoặc sửa file.
