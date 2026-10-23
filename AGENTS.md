# AGENTS.md — WordPress AI Content Factory v1.2.0 + Qwen Local Writer 0.3.0-beta

## Source of truth
Repo duy nhất `thuexemayhanoi/wp-plugin`, branch `main`. Không nhầm với chatbot hoặc dự án khác. Giữ lịch sử Git và tương thích dữ liệu WordPress; không xóa bảng dữ liệu đang sử dụng.

## Product contract
Luồng chính: cấu hình API key+Model ID trong Cài đặt API → chọn AI / 1–100 bài / 1.500–10.000 từ / H2–H6 / danh mục cha-con / Nháp hoặc Xuất bản / tiêu đề hoặc Excel → chạy nền. Không lộ QA/reviewer trong workflow mới; không phá workflow cũ. Markdown gốc lưu `_masf_markdown` và hiển thị trong editor, chỉ HTML đã lọc được đưa ra frontend. API model output, quota, billing phải báo lỗi thật; không hứa số từ/heading chính xác. Mặc định draft, publish chỉ khi quản trị chủ động chọn và đủ quyền.

## Security & updates
Mọi action quản trị cần capability+nonce; API key mã hóa AES-256-GCM bằng WP salts; không log/echo/commit secrets. Chỉ HTTPS API endpoints allowlisted. Escape output, validate Excel/CSV và sanitize Markdown. Không tạo API request khi chạy smoke tests. Không thêm dependency nặng.

## QA vận hành (không phải vòng QA bài AI)
Sau sửa chạy `php -l` tất cả PHP, `php tests/smoke-v120.php`, `node --check assets/admin.js`, `python3 scripts/build_plugin.py`, validate ZIP. CI GitHub Actions phải PASS mới khẳng định bản build. Không gán test mock là test production. Cập nhật README, CHANGELOG, version PHP header/constant/readme.txt. Git tag đúng phiên bản để phát hành Release. Nêu rõ chưa kiểm thử API thật khi bàn giao.

## Qwen Local Writer (`qwen-local-writer/`, 0.3.0-beta, nhánh `feat/qwen-local-no-paid-api`)
Plugin độc lập, không dùng API AI thương mại, không API key, không suy luận trên PHP. Qwen3-0.6B ONNX (`onnx-community/Qwen3-0.6B-ONNX`, revision ghim `58ca03a5…`) chạy trong trình duyệt quản trị (WebGPU hoặc WASM). Transformers.js/ONNX Runtime Web đóng gói từ npm, kiểm tra sha512/sha256 (`scripts/vendor_qwen_deps.py`); không commit file vendor, không commit trọng số model.
- Luôn lưu **Nháp**; không xuất bản, không ghi đè bài đã rời trạng thái Nháp hoặc của người khác. Không tải mã Qwen ở frontend công khai.
- QA tự động (ngưỡng 70) chia nhóm kỹ thuật / kiểm chứng dữ kiện / SEO; biên tập là bước của con người. Không coi điểm QA là bằng chứng nội dung đúng; không nới ngưỡng để che lỗi.
- Không sửa plugin Multi AI SEO Content Factory 1.2.0 khi làm việc trên Qwen; không xóa dữ liệu WordPress.
- Kiểm thử sau sửa: `php -l` mọi PHP; `php qwen-local-writer/tests/quality.php`; `php qwen-local-writer/tests/markdown.php`; `node --test qwen-local-writer/tests/js/*.test.mjs`; `python3 scripts/build_qwen.py`; trên WordPress dùng một lần: `wp eval-file qwen-local-writer/tests/wp-integration.php`, `node qwen-local-writer/tests/e2e/admin-ui.e2e.cjs` (model giả — chỉ kiểm thử hệ thống), `qwen-local-writer/tests/rollback.sh`. Model thật chỉ chạy trong workflow `Qwen real inference` (GitHub Actions); ghi rõ phần nào chưa kiểm thử (GPU WebGPU thật, Safari iOS, website thật).
- Cập nhật version ở header, `QLW_VERSION`, `readme.txt` (Stable tag) và CHANGELOG cùng lúc; `build_qwen.py` từ chối build nếu lệch.
