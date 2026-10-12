# AGENTS.md — WordPress AI Content Factory v1.2.0

## Source of truth
Repo duy nhất `thuexemayhanoi/wp-plugin`, branch `main`. Không nhầm với chatbot hoặc dự án khác. Giữ lịch sử Git và tương thích dữ liệu WordPress; không xóa bảng dữ liệu đang sử dụng.

## Product contract
Luồng chính: cấu hình API key+Model ID trong Cài đặt API → chọn AI / 1–100 bài / 1.500–10.000 từ / H2–H6 / danh mục cha-con / Nháp hoặc Xuất bản / tiêu đề hoặc Excel → chạy nền. Không lộ QA/reviewer trong workflow mới; không phá workflow cũ. Markdown gốc lưu `_masf_markdown` và hiển thị trong editor, chỉ HTML đã lọc được đưa ra frontend. API model output, quota, billing phải báo lỗi thật; không hứa số từ/heading chính xác. Mặc định draft, publish chỉ khi quản trị chủ động chọn và đủ quyền.

## Security & updates
Mọi action quản trị cần capability+nonce; API key mã hóa AES-256-GCM bằng WP salts; không log/echo/commit secrets. Chỉ HTTPS API endpoints allowlisted. Escape output, validate Excel/CSV và sanitize Markdown. Không tạo API request khi chạy smoke tests. Không thêm dependency nặng.

## QA vận hành (không phải vòng QA bài AI)
Sau sửa chạy `php -l` tất cả PHP, `php tests/smoke-v120.php`, `node --check assets/admin.js`, `python3 scripts/build_plugin.py`, validate ZIP. CI GitHub Actions phải PASS mới khẳng định bản build. Không gán test mock là test production. Cập nhật README, CHANGELOG, version PHP header/constant/readme.txt. Git tag đúng phiên bản để phát hành Release. Nêu rõ chưa kiểm thử API thật khi bàn giao.
