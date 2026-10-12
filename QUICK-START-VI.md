# Hướng dẫn WordPress v1.2.0 — 2 bước

1. Vào **AI Content Factory → Cài đặt API**. Chọn Claude, Gemini hoặc GPT và nhập **API key** cùng **Model ID** đúng tài khoản. Bấm **Lưu API & Model** (ô API trống giữ khóa trước). Tùy chọn nhấn **Thử kết nối** (có thể tốn ít token).
2. Vào **AI Content Factory → Viết bài nhanh**. Chọn AI, số bài 1–100, 1.500–10.000 từ/bài, số H2–H6, danh mục cha/con, trạng thái **Nháp** (mặc định). Nhập tiêu đề (mỗi dòng một bài) hoặc file Excel/CSV, tích xác nhận phí và nhấn **Bắt đầu viết bài**.

Đợi hàng đợi nền hoàn tất, không cần mở liên tục bảng quản trị. Vào **Bài viết → Tất cả bài viết**, mở bài nháp. Markdown gốc hiển thị trong hộp **Markdown gốc của bài AI** dưới trình sửa bài; nội dung đã chuyển thành HTML cho WordPress. Bài dài phụ thuộc khả năng trả đủ output của model, không cam kết đạt chính xác số từ/heading. Khi lỗi API, mở **Chiến dịch viết bài** để xem nguyên nhân và thử lại sau khi xử lý.

**Cài plugin**: Actions → chạy CI thành công → tải artifact → giải nén artifact để lấy file `multi-ai-seo-content-factory.zip` → WordPress Plugins → Add New → Upload Plugin → cài đặt/thay thế. Sao lưu trước khi cập nhật và thử 1 bài ở chế độ Nháp.
