# Qwen Local Writer — báo cáo kiểm thử (0.2.0-beta → 0.3.0-beta)

Ngày: 2026-10-23 · Nhánh `feat/qwen-local-no-paid-api` · PR #1

## Tóm tắt (lần chạy model thật mới nhất đã PASS: run 39907043073, commit 6d56b1a)

| Kiểm thử | Kết quả |
|---|---|
| 4 bài VI/EN, Qwen3-0.6B thật, CPU (Node + onnxruntime-node), mã điều phối của plugin | 1.476–1.720 từ, 7–8 H2, **QA 90–100**, 233–362 giây/bài |
| 1 bài VI trong Chromium qua giao diện plugin thật (WASM q4, không GPU) → lưu nháp WordPress | **1.716 từ, 29 phút, nháp #5, QA 100**, tải lại model từ cache 5 giây |
| Máy chủ trình duyệt liên hệ | chỉ `huggingface.co`, `us.aws.cdn.hf.co`, `secure.gravatar.com` (WordPress) |
| Plugin trên WordPress sạch 6.9.10/PHP 8.1 và 7.1.3/PHP 8.3 + MySQL 8 (cùng plugin cũ 1.2.0) | tích hợp 49/49, E2E 45/45, gỡ cài đặt giữ bài |

QA ≥ 70 là **điểm kỹ thuật**; mọi bài vẫn có lỗi nội dung cần biên tập (mục 2.2, 2.5, 2.6). Mẫu gốc: `docs/qwen-samples/`.
Sau lần chạy này chỉ sửa thêm 2 chi tiết làm sạch (mục 2.6) — lần chạy model thật cho commit cuối được ghi ở PR #1.

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

**Cả 4 đạt ngưỡng kỹ thuật 70** và nằm trong 1.500 ± 10% từ.

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

### 2.4 Lần chạy 4 (run 39901303799, commit e1a46d9) — biến thiên do lấy mẫu ngẫu nhiên
| Bài | Số từ | H2 | QA |
|---|---|---|---|
| `vi-brief-q4f16` | 1.874 | 8 | 100 |
| `en-brief-q4f16` | 1.533 | 8 | 100 |
| `vi-general-q4` | 1.675 | 6 | **55** — lặp nguyên văn đoạn văn, 9/64 câu lặp, từ khóa lặp 11 lần |
| `en-general-q4` | 1.488 | 8 | 90 — từ khóa lặp dày |

Job **FAIL đúng**: QA chặn bài lặp. Nguyên nhân gốc: lời gọi "viết tiếp" chép lại đoạn đã có (kèm dòng prompt "Bài viết: …")
và từ khóa nằm trong prompt mọi mục. Đã sửa: loại câu đã xuất hiện ở bất kỳ phần nào của bài (kiểm tra trước khi quyết
định viết lại), xoá dòng lặp lại prompt, chỉ đưa từ khóa vào dàn ý/mở bài/kết luận, bỏ H2 rỗng.

### 2.5 Lần chạy 5 (run 39903514077, commit 66ecdc6) — PASS toàn bộ
| Bài | dtype | Số từ | H2 | QA | Thời gian |
|---|---|---|---|---|---|
| `vi-brief-q4f16` | q4f16 | 1.684 | 7 | 100 | 330 s |
| `en-brief-q4f16` | q4f16 | 1.614 | 8 | 100 | 283 s |
| `vi-general-q4` | q4 | 1.733 | 7 | 100 | 380 s (2 lần viết lại) |
| `en-general-q4` | q4 | 1.403 | 7 | 95 (1 mục < 60 từ) | 229 s |
| Chromium/WASM qua giao diện plugin, VI có brief | q4 | 1.637 | 7 + Kết luận | 85 (trừ 10 kể trải nghiệm, trừ 5 thiếu từ khóa) | 23,9 phút, 1,4–1,5 token/s, 10 lần gọi |

Không còn trộn mục, lặp đoạn, câu chatbot hay tiêu đề rác. Lỗi nội dung vẫn còn (con người phải sửa): dùng "mua xe" thay
vì thuê, xưng "tôi/chúng tôi", câu chung chung, một câu dẫn meta ở phần kết (đã thêm luật làm sạch sau lần chạy này).

### 2.6 Lần chạy 6 (run 39907043073, commit 6d56b1a) — PASS toàn bộ
| Bài | Số từ | H2 | QA | Thời gian | Ghi chú QA |
|---|---|---|---|---|---|
| `vi-brief-q4f16` | 1.568 | 7 | 90 | 299 s | trừ 10: kể trải nghiệm |
| `en-brief-q4f16` | 1.476 | 8 | 90 | 267 s | 1 mục < 60 từ; giá "500,000 VND" **không có trong brief** bị đánh dấu |
| `vi-general-q4` | 1.720 | 7 | 100 | 362 s | — |
| `en-general-q4` | 1.546 | 7 | 100 | 233 s | — |
| Chromium/WASM qua giao diện plugin, VI có brief | 1.716 | 7 + Kết luận | 100 | 29,3 phút, 1,1–1,3 token/s, 10 lần gọi, 2.198 token | — |

Bài trong trình duyệt lộ thêm 2 lỗi trình bày, đã sửa sau lần chạy này: cả 6/7 H2 bắt đầu bằng cùng tiền tố từ khóa
"Thuê xe máy Long Biên:" (nhồi từ khóa — nay bỏ tiền tố dùng chung cho ≥ 3 tiêu đề) và một dòng nhắc lại tiêu đề mục
ngay trên đoạn đầu (nay bị bỏ). Lỗi nội dung vẫn còn: "mua xe" thay vì thuê, câu vô nghĩa ("xe có thể thổi bóng"),
NAP chép từ brief nhưng viết tắt sai ("Đn:"). QA 100 **không** có nghĩa là bài đúng — vẫn phải biên tập.

### 2.7 Lần chạy 7 (run 39910343866, commit 453325b) — PASS
Node CPU: 4 bài 1.547–1.806 từ, 8 H2, QA (0.2) 90–100 (vi-brief 1.658/90 kể trải nghiệm; en-brief 1.547/95 thiếu từ khóa; vi-general 1.806/90 từ khóa dày; en-general 1.607/100), 241–374 giây/bài. Chromium/WASM: **1.776 từ, 30,5 phút**, 1,2–1,3 token/s, lưu nháp #5,
tải lại từ cache 5 giây, chỉ liên hệ Hugging Face + gravatar. Lỗi trình bày còn lại (đã sửa sau lần chạy này, có test hồi quy):
tiền tố từ khóa dạng biến thể ("Thuê xe máy:", "Thuê xe máy tại Long Biên:"), tiêu đề bài lặp ở đầu một mục, câu dẫn
"Bài viết "…" bắt đầu bằng nội dung này:" và "Đoạn văn này đưa ra…". Nội dung vẫn lẫn "mua xe/người mua".

### 2.9 Lần chạy 8 (run 39913479875, commit 060f29b — QA 0.3.0) — PASS
CPU: vi-brief 1.669 từ / QA 80 (bắt được 2 lỗi thật: "xe điện như Honda Vision", "thị trấn Hà Nội"); en-brief 1.432 / 100;
vi-general 1.655 / 100; en-general 1.527 / 95. Chromium/WASM: **1.664 từ, 17,5 phút** (2,1 token/s trên runner này), nháp #5,
QA 80 — 7 câu "người mua/mua xe" và 2 câu "nổi tiếng" được liệt kê cần kiểm chứng; dòng "THUÊ, không phải mua bán" trong prompt
**không** đủ để model 0.6B bỏ lỗi này.

### 2.10 Lần chạy 9 (run 39914553591, commit 97af80d) — FAIL (đúng)
CPU: vi-brief **1.675 từ / QA 40 → không đạt**; en-brief 1.544 / 85; vi-general 1.561 / 100; en-general 1.543 / 100.
Bài vi-brief có giá rác "000000000đ/ngày", "Honda Vision 150.000đ/ngày" (brief: 200.000đ), 4 câu "tôi đã…/chúng tôi đã…".
QA chặn đúng; nhưng 3 cảnh báo mua/thuê là **báo nhầm** (câu "không phải mua xe mà là thuê" nói đúng) và một cảnh báo giá EN là
báo nhầm ("deposits" số nhiều). Đã sửa hai lỗi của QA và thêm luật bỏ câu có số toàn 0; ngưỡng 70 giữ nguyên.

### 2.8 0.3.0-beta: chấm lại bài thật bằng QA mới (kiểm chứng dữ kiện + SEO)
Cùng các bài gốc trong `docs/qwen-samples/`, cùng brief như CI, chấm bằng QA 0.3.0 (không chạy lại model):

| Bài | QA 0.2.0 | QA 0.3.0 | Cảnh báo mới |
|---|---|---|---|
| `vi-brief-q4f16` | 100 | 80 | giá "Honda Vision 2.000.000đ/tuần" **khác brief** (1.000.000đ/tuần); 2 câu "mua xe" trong bài thuê xe |
| `en-brief-q4f16` | 100 | 100 | — (không thấy mẫu sai đã biết; **không** có nghĩa là bài đúng) |
| `vi-general-q4` | 100 | 100 | — |
| `en-general-q4` | 95 | 95 | 1 mục < 60 từ |
| `browser-vi-wasm` | 85 | 60 | 6 câu "người mua/mua xe", 1 câu "chúng tôi đã…", câu dẫn meta ở kết luận (đã có luật làm sạch), thiếu nguyên văn từ khóa |
| fixture lỗi (run 1) | 60 | 55 | thêm cảnh báo H2 lặp tiền tố |

Lần chạy model thật cho commit 0.3.0-beta cuối (prompt "THUÊ, không phải mua bán" mới) được ghi trong
[FINAL-AUDIT-REPORT.md](FINAL-AUDIT-REPORT.md) mục 8 và PR #1.

## 3. Kiểm thử tự động (không dùng model)

| Bộ kiểm thử | Kết quả cục bộ |
|---|---|
| `php tests/quality.php` (QA, gồm câu và bài thật bị lỗi) | 60/60 |
| `php tests/markdown.php` (Markdown → block) | 18/18 |
| `node --test tests/js/*.test.mjs` (điều phối, Worker, lỗi thật của Qwen) | 29/29 |
| `wp eval-file tests/wp-integration.php` (WP 7.1.3 + SQLite, cùng plugin cũ 1.2.0) | 66/66 (CI có Yoast + Rank Math: 68/68) |
| `tests/rollback.sh` (0.3.0-beta ↔ 0.2.0-beta, dữ liệu giữ nguyên) | PASS |
| `tests/e2e/admin-ui.e2e.cjs` (Chromium, model giả, cùng plugin cũ) | 59/59 |
| Smoke plugin cũ `tests/smoke-v120.php` | 29/29 |
