# Multi AI SEO Content Factory v1.1.0 — cách viết bài đơn giản

Cài ZIP qua **WordPress → Plugins → Add New → Upload Plugin** rồi **Activate/Update**. Trước khi cập nhật plugin đã dùng, sao lưu database và plugin hiện tại; v1.1.0 vẫn dùng các bảng/cấu hình và posts cũ, không tự xóa bài/Matrix.

## Cách chạy

1. Mở menu **AI Content Factory → Viết bài nhanh**.
2. Chọn **Claude, Gemini hoặc OpenAI**. Dán **API key mới**, hoặc để trống nếu trang cho biết key đó **đã lưu**. Khóa lưu mã hóa phía máy chủ; nút hiển thị chỉ hiện key **đang nhập**, không đọc lại key đã lưu.
3. Nhập **Model ID** đã được tài khoản API cấp quyền sử dụng (có thể vào **API Providers → Lấy danh sách model → Lưu** để tra cứu và **Test Connection**). Không có model mặc định nào được giả định là tài khoản nào cũng có.
4. Chọn **1–100 bài**, danh mục WordPress, độ dài mục tiêu **1.000–5.000 từ**, ngôn ngữ. Chọn output tokens: **4.000 / 8.000 / 16.000 / 32.000 / 64.000**. Mặc định 16.000; chỉ chọn giới hạn phù hợp model và tài khoản. 4.000 token thường **không đủ** để đảm bảo bài 5.000 từ, đặc biệt khi API yêu cầu JSON và token suy luận. Chọn cao không có nghĩa model sẽ trả đủ hoặc bị trừ toàn bộ token; giới hạn thực tế còn tùy model/provider.
5. Dán **mỗi tiêu đề trên một dòng** hoặc tải **.xlsx/.csv** có cột `title`. Có thể kết hợp cả hai. Nếu chọn 100 bài cần ít nhất 100 dòng; hệ thống lấy đúng **100 dòng đầu**, bài trùng/không hợp lệ sẽ bị bỏ qua.
6. Tích xác nhận API tính phí và nhấn **Tạo chiến dịch và chạy viết bài**. Bài mới được xếp hàng tự động, **chỉ lưu nháp**, không tự publish.
7. Theo dõi **Tổng quan / Bài lỗi cần kiểm tra** bên dưới form, **Matrix**, **Campaigns** và **Logs**. Khi hoàn tất, kiểm tra nội dung, độ dài, SEO, các thông tin được xác thực trước khi đăng.

## API key và số dư

- Plugin có thể nhận dạng một số lỗi thanh toán/hạn mức API (ví dụ `insufficient_quota` của OpenAI, HTTP 402 và `billing_error`, spend limit), thông báo và **tạm dừng chiến dịch**. Trường hợp HTTP 429 do vượt tốc độ sẽ retry theo backoff, **không kết luận đã hết tiền**.
- **Không có số dư tiền API được hiển thị** bởi vì một request tạo nội dung hoặc Test Connection không xác nhận được số dư thanh toán thực tế của tất cả nhà cung cấp. Hãy dùng cổng thanh toán chính thức của Claude/Gemini/OpenAI.
- Nếu API hết tiền/model lỗi: sửa Billing, key hoặc Model ID; quay lại Matrix kiểm tra error, vào Campaigns nhấn **Retry Failed**, rồi **Resume** (không bỏ qua bước sửa nguyên nhân gốc).
- API key không được lưu trong file ZIP hoặc đưa vào log. Khóa mới lưu bằng AES-256-GCM từ WordPress salts; khi đổi salts phải nhập lại.

## Thận trọng về chi phí và vận hành

- Tạo chiến dịch có thể phát sinh tiền API cho nhiều lần generate và repair. Giới hạn output chỉ là **trần request**, không phải ngân sách USD.
- Background runner dùng Action Scheduler/WP-Cron; server ít truy cập có thể cần cấu hình cron thật trên hosting. Không cần mở màn hình admin để worker chạy.
- Một chiến dịch 100 bài được xử lý theo lô 20 với hạn một worker mặc định, không gửi 100 request cùng lúc.
- Bản ZIP v1.1.0 có **kiểm thử giả lập cục bộ** cho luồng tạo nhanh và phân loại lỗi; **chưa kiểm thử với API key thực, WordPress/Hostinger live hoặc 100 bài có tính phí**. Nên kiểm tra thử 1–5 bài trước.
