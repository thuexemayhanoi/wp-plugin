# Security

Report suspected credential exposure or vulnerabilities privately to the repository owner; do not paste API keys, admin cookies, wp-config.php or private logs into public Issues.

Only WordPress administrators with sufficient capabilities should configure API providers or start paid campaigns. Use HTTPS, keep WordPress/PHP updated, and rotate exposed keys via the relevant provider portal. The plugin stores keys encrypted with WordPress salts; changing salts may require re-entering keys. Do not commit real keys, `.env`, WordPress database exports or production-specific configs.

Release packages are built from a tracked commit, then manually installed. CI does not connect to a live WordPress site or use paid API keys.

## Qwen Local Writer (`qwen-local-writer/`)

- **Không có API key và không gửi nội dung cho dịch vụ AI.** Model chạy trong trình duyệt quản trị. Trình duyệt chỉ tải trọng số từ `huggingface.co`/CDN của Hugging Face (hoặc thư mục tự lưu trữ cùng tên miền). Nội dung bài chỉ được gửi tới `admin-ajax.php` của chính website khi bấm Chấm QA hoặc Lưu.
- **Quyền:** màn hình và AJAX yêu cầu `edit_posts` + nonce `qlw-save-draft`; cài đặt yêu cầu `manage_options` + nonce `qlw-settings`; xác nhận biên tập yêu cầu `edit_post` + nonce riêng cho từng bài. Plugin chỉ tạo/cập nhật **bản nháp của chính người dùng**, chỉ khi bài còn ở trạng thái Nháp và có `_qlw_markdown`; kiểm tra `assign_term` khi gán danh mục.
- **Nội dung:** Markdown bị từ chối nếu có `<script>`, `<iframe>`, `javascript:`, thuộc tính `on…=`; được chuyển sang block H2–H3/đoạn/danh sách với mọi văn bản đã escape, liên kết chỉ `https`, sau đó qua `wp_kses_post`. Metabox và giao diện escape mọi dữ liệu.
- **Đường dẫn model:** chỉ model và revision được ghim; nguồn tự lưu trữ phải cùng origin với website. Trang viết bài gửi COOP/COEP (`credentialless`) chỉ trên màn hình của plugin.
- **Gỡ cài đặt:** chỉ xóa `qlw_settings` và `qlw_version`; không xóa bài viết hay metadata.
- **Thư viện đóng gói:** Transformers.js 4.3.1 (Apache-2.0) và ONNX Runtime Web 1.31.0-dev (MIT) lấy từ npm và kiểm tra tính toàn vẹn khi build. GitHub secret scanning báo nhầm một chuỗi trong bản minified của Transformers.js; vì vậy file vendor không được commit.
