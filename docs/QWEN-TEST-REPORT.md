# Qwen Local Writer 0.2.0-beta — báo cáo kiểm thử

Ngày: 2026-10-23 · Nhánh `feat/qwen-local-no-paid-api` · PR #1

## 1. Đo thực tế với model thật (probe CI, GitHub Actions ubuntu-latest, 4 vCPU, 16 GB RAM, không GPU)

Model `onnx-community/Qwen3-0.6B-ONNX` revision `58ca03a5e7b354b480b1a82b34bde8dc7b854cc0`
(`model_q4f16.onnx` 569.789.750 byte, `model_q4.onnx` 919.096.585 byte), Transformers.js 4.3.1.

| Môi trường | dtype | Tốc độ | Ghi chú |
|---|---|---|---|
| Node + onnxruntime-node (CPU gốc) | q4 | 22,6–26,4 token/s | VI ~1,16–1,27 token/từ, không lẫn chữ Hán |
| Node + onnxruntime-node (CPU gốc) | q4f16 | 25,4–26,8 token/s | VI ~1,21 token/từ |
| Chromium, Worker, WASM, cross-origin isolated (2 luồng) | q4 | 2,6 token/s | khởi tạo model 11,6 s |
| Chromium, Worker, WASM, không isolation (1 luồng) | q4 | 1,5 token/s | |
| Chromium, WebGPU SwiftShader (GPU giả lập phần mềm) | q4 | 0,13 token/s | vì vậy chế độ Tự động bỏ qua adapter phần mềm |

## 2. Bài ~1.500 từ VI/EN viết bằng model thật qua mã điều phối của plugin

### 2.1 Lần chạy 1 (run 39897057193, commit 110dd5e — bị huỷ giữa chừng, vẫn có 1 bài VI hoàn chỉnh)
Bài VI có brief: 1.682 từ, QA lúc đó **100/100** nhưng nội dung **hỏng cấu trúc**: tiêu đề còn `*H2:`, mục 1 viết lẫn
nội dung mọi mục khác, kết luận tự mô tả yêu cầu ("Đoạn kết luận có thể được viết như sau:", "(Đ dài hơn 105 từ.)").
→ Đây là lỗi thật mà kiểm thử giả lập không thấy. Đã sửa prompt (bỏ dàn ý khỏi prompt từng mục), làm sạch đầu ra và bổ
sung 4 kiểm tra QA. Bài này được giữ làm fixture: `qwen-local-writer/tests/fixtures/qwen-real-vi-v020-flawed.md` —
QA mới chấm **60/100 → NEEDS_REVISION**.

### 2.2 Lần chạy 2 (run 39898298326, commit 08ba39a) — Node + onnxruntime-node, CPU 4 vCPU
| Bài | dtype | Số từ | H2 | QA | Thời gian | Token | Ghi chú QA |
|---|---|---|---|---|---|---|---|
| VI có brief NAP/giá (`vi-brief-q4f16`) | q4f16 | 1.603 | 7 | 90 | 259 s | 2.233 | trừ 10: kể "trải nghiệm cá nhân" |
| EN có brief (`en-brief-q4f16`) | q4f16 | 1.641 | 8 | 90 | 240 s | 2.237 | trừ 10: giá 500,000 / 250,000 VND **không có trong brief** |
| VI không brief (`vi-general-q4`) | q4 | 1.556 | 7 | 100 | 213 s | 1.927 | — |
| EN không brief (`en-general-q4`) | q4 | 1.510 | 7 | 95 | 183 s | 1.853 | trừ 5: 1 mục < 60 từ |

Bản gốc 4 bài (chưa sửa): `docs/qwen-samples/`. **Cả 4 đạt ngưỡng kỹ thuật 70** và nằm trong 1.500 ± 10% từ.

**Đánh giá biên tập thủ công (con người phải làm, QA không làm được):** cấu trúc sạch (không còn trộn mục/câu meta),
nhưng Qwen3-0.6B vẫn **bịa và sai kiến thức**, ví dụ: "Nguyễn Tú – người đã từng là một nhà thiết kế nổi tiếng",
"Honda Air Blade … xe điện thông minh", "Hà Nội … thị trấn yên tĩnh", "Central Highlands" trong Hà Nội, gán cọc 2–3 triệu cho
xe 50cc (brief ghi 4 triệu), xưng "tôi/chúng tôi", câu vô nghĩa ("xe tay gặt", "giấy dán dập phù hợp với độ tuổi"),
câu kiểu chatbot ở cuối bài EN. Kết luận: plugin tạo được **bản nháp ~1.500 từ đúng cấu trúc, đạt QA kỹ thuật ≥ 70**,
nhưng **không đủ chất lượng để đăng nếu không biên tập kỹ**. Sau lần chạy này đã bổ sung: bỏ tiền tố "7th Section:"/
"Ghi chú:" trong tiêu đề, xoá câu chatbot ("Let me know…", "hãy hỏi tôi"), loại H2 gần trùng tiêu đề bài.

### 2.3 Chromium qua giao diện plugin thật (run 39898298326, job 120234142977, WordPress 7.1.3 + MySQL 8, WASM)
Luồng thật: đăng nhập wp-admin → trang Qwen Local Writer (cross-origin isolated) → bấm **Tải Qwen** (Worker, Transformers.js
và ONNX Runtime đóng gói trong plugin, trọng số tải từ Hugging Face revision đã ghim) → viết bài VI có brief → **Lưu nháp**.

| Chỉ số | Kết quả |
|---|---|
| Thiết bị | WASM q4, 2 luồng (runner không có GPU; WebGPU chỉ là SwiftShader nên chế độ Tự động bỏ qua đúng) |
| Khởi tạo model (lần đầu, mạng CI) | 0,17 phút |
| Viết bài | **28 phút**, 1,2–1,3 token/s, 8 lần gọi model, 2.108 token |
| Kết quả | **1.599 từ**, 5 H2 + Kết luận, lưu thành **bản nháp WordPress #5**, QA **90/100** (trừ 10: kể trải nghiệm) |
| Tải lại trang, tải model lần 2 | 0,08 phút — dùng cache trình duyệt |
| Máy chủ được liên hệ | `huggingface.co`, `us.aws.cdn.hf.co` (CDN của Hugging Face), `secure.gravatar.com` (avatar của WordPress) — không CDN thư viện, không API AI |

Job báo FAIL chỉ vì bộ lọc host của **chính bài kiểm thử** chưa có `us.aws.cdn.hf.co`; đã sửa. Bài viết cũng lộ 2 lỗi đã sửa:
model chép số `Date.now()` có trong tiêu đề thử nghiệm vào bài (QA nay đánh dấu số dài ≥ 9 chữ số không có trong brief),
và một gạch đầu dòng bị cắt giữa chừng kết thúc bằng dấu phẩy (nay bị bỏ). Nội dung vẫn có lỗi kiến thức ("thị trấn Long
Biên", "xe được bán ra") — cần biên tập.

## 3. Kiểm thử tự động (không dùng model)

| Bộ kiểm thử | Kết quả cục bộ |
|---|---|
| `php tests/quality.php` (QA, gồm bài thật bị lỗi) | 27/27 |
| `php tests/markdown.php` (Markdown → block) | 18/18 |
| `node --test tests/js/*.test.mjs` (điều phối, Worker, lỗi thật của Qwen) | 21/21 |
| `wp eval-file tests/wp-integration.php` (WP 7.1.3 + SQLite, cùng plugin cũ 1.2.0) | 49/49 |
| `tests/e2e/admin-ui.e2e.cjs` (Chromium, model giả, cùng plugin cũ) | 45/45 |
| Smoke plugin cũ `tests/smoke-v120.php` | 27/27 |
