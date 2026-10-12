# Xử lý và khôi phục lỗi

| Hiện tượng | Cách xử lý |
|---|---|
| Queue không chạy | Kiểm tra campaign RUNNING/BATCH, không Stop/Pause; hạn bài/ngày; cron/loopback; Tools → Scheduled Actions, nhóm masf-content-factory |
| 401/403/404 | Lưu lại key/model hợp lệ, Test Connection; Retry Failed rồi Resume |
| 429 | Chờ backoff/Retry-After, giảm RPM hoặc số worker; kiểm tra quota ở nhà cung cấp |
| Timeout | Bài/checkpoint giữ lại, chi phí để dự phòng; giảm số từ, tăng output tokens/timeout trong giới hạn hosting; Retry nếu đã FAILED |
| AI trả sai JSON | Chọn model có hỗ trợ JSON và text API tương ứng; đọc error; Retry có giới hạn |
| REPAIR/BLOCKED | Mở detail QA, bổ sung facts/nguồn/brand profile, sửa cấu hình khi Pause, Retry Failed rồi Resume |
| Tiền/phone không khớp | Nhập nguồn thực tế vào Brand Profile, không bỏ gate chỉ để publish |
| Excel chứa công thức | Trong Excel dùng Copy → Paste Values rồi lưu .xlsx; hoặc CSV UTF-8 |
| Import trùng | Dòng đã tồn tại được bỏ qua; không tự tạo một bản có suffix |
| Đổi security salts | Các key DB không giải mã được; nhập lại key tại API Providers |
| Post bị xóa/trash | Plugin không tạo lại tự động. Dừng campaign và xem checkpoint để xử lý chủ động |

Pause giữ bản nháp/checkpoint. Stop là dừng chủ động, có thể Resume sau. Retry Failed chỉ reset FAILED/BLOCKED chưa giao; thao tác đặt campaign Pause để có thời gian sửa dữ liệu, sau đó bấm Resume. Nó không chạy lại bài đã giao/đăng/lên lịch.

Watchdog phục hồi lease worker hết hạn sau khoảng 3 phút, tăng retry count. WordPress có thể chạy watchdog muộn nếu cron không hoạt động. Không xóa bảng/Matrix hoặc toàn bộ action của website để xử lý một bài lỗi.

Nếu PHP lỗi sau cài đặt, dùng chế độ Recovery của WordPress hoặc File Manager của đúng website để đổi tên thư mục plugin rồi đăng nhập lại. Vô hiệu hóa plugin không xóa các bài đã đăng. Giữ bản ZIP và backup DB trước thao tác cập nhật production; bản giao này chưa được cài lên website thật.
