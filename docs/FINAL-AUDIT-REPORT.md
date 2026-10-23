# Final audit report — Qwen Local Writer 0.3.0-beta

- Repository `thuexemayhanoi/wp-plugin`, nhánh `feat/qwen-local-no-paid-api`, Pull Request #1 (chưa merge, không triển khai production).
- Mốc trước phiên audit này: `453325b` (0.2.0-beta).
- Kết quả GitHub Actions và commit SHA cuối được ghi ở mục 8 và trong mô tả PR #1.
- Bảng chức năng chi tiết: [FEATURE-INVENTORY.md](FEATURE-INVENTORY.md). Số đo model thật: [QWEN-TEST-REPORT.md](QWEN-TEST-REPORT.md).

## 1. Chức năng đã có trước phiên này (0.2.0-beta)
- Qwen3-0.6B ONNX chạy trong trình duyệt quản trị. Có WebGPU, dự phòng WASM đa luồng, streaming, nút dừng và tải từ cache. Runtime đóng gói, không CDN.
- Viết theo dàn ý 7 H2, khoảng 1.500 từ, VI/EN, và làm sạch nhiều lỗi thật của model.
- Lưu nháp an toàn, chống trùng tiêu đề, cập nhật đúng bản nháp. Không ghi đè bài đã rời Nháp.
- QA kỹ thuật 70/100. Giao diện responsive, khôi phục bản đang soạn, tự lưu trữ model.
- Kiểm thử: unit, tích hợp WordPress thật, E2E với model giả, và CI chạy model thật (CPU + Chromium).

## 2. Chức năng hoàn thiện trong phiên này (0.3.0-beta)
1. **QA bốn phần**: Kỹ thuật, Kiểm chứng dữ kiện, SEO (tự động) và Biên tập (con người xác nhận trong trình soạn bài). Báo cáo không bao giờ ghi "đã xác minh"; mục *Câu cần kiểm chứng* liệt kê nguyên câu.
2. **Kiểm chứng dữ kiện** dựng từ lỗi thật của Qwen:
   - giá gắn sai mẫu xe so với brief;
   - xe xăng bị gọi là xe điện (Air Blade);
   - "thị trấn Long Biên", Hà Nội đặt cạnh Tây Nguyên;
   - lẫn mua/thuê trong bài về thuê xe;
   - hứa "không cần đặt cọc";
   - các khẳng định "nổi tiếng / hàng đầu / thành lập năm";
   - đánh giá sao và nguồn "nghiên cứu" tự bịa;
   - năm không có trong brief.
3. **SEO**:
   - kiểm tra H2 nhồi từ khóa hoặc lặp cùng tiền tố, độ dài tiêu đề, từ khóa trong tiêu đề;
   - phát hiện bài gần trùng tiêu đề/nội dung trong tối đa 40 bài liên quan (tìm theo từ khóa, tiêu đề và 2 cụm câu nguyên văn của bài);
   - từ khóa chính Yoast SEO/Rank Math cho nháp mới (không ghi đè).
4. **Bảo vệ dữ liệu**: không ghi đè bản nháp của người khác (`not_owner`). Xác nhận biên tập tự bỏ khi Qwen viết lại.
5. **Ổn định và phục hồi**:
   - lưu khôi phục cả khi đang stream;
   - báo đúng khi Worker sập, không còn lỗi chưa xử lý trên trang;
   - nút *Giải phóng bộ nhớ*;
   - `maybe_upgrade` khi thay ZIP, có `tests/rollback.sh`.
6. **Dữ kiện đã xác minh của cửa hàng** (cài đặt quản trị) + nút chèn vào brief khi cần.
7. **Prompt**: bài thuê xe nói rõ "THUÊ, không phải mua bán". Bỏ tiền tố từ khóa lặp ở mọi H2 và dòng nhắc lại tiêu đề mục.
8. **CI**:
   - job tương thích WooCommerce + Yoast SEO + Rank Math;
   - kiểm thử nâng/hạ phiên bản;
   - quét lỗi PHP Fatal;
   - real-run in ra danh sách câu cần kiểm chứng.

## 3. Lỗi phát hiện

| # | Lỗi | Phát hiện bởi | Mức |
|---|---|---|---|
| 1 | QA cho 100/100 với bài chứa thông tin sai (giá Vision 2.000.000đ/tuần ≠ brief 1.000.000đ; "người mua" trong bài thuê xe; Air Blade "xe điện") | chấm lại bài thật | cao |
| 2 | Biên tập viên (role editor) có thể ghi đè bản nháp Qwen của người khác | audit mã | trung bình |
| 3 | Worker sập bị báo là "Trình duyệt quá cũ" | E2E mới | trung bình |
| 4 | Lỗi Worker lọt thành lỗi chưa xử lý trên trang | E2E mới | thấp |
| 5 | Trong lúc stream, bản khôi phục chỉ lưu sau mỗi phần (debounce không kích hoạt) | audit mã | trung bình |
| 6 | Thay ZIP (nâng/hạ cấp) không cập nhật `qlw_version` | audit mã + rollback test | thấp |
| 7 | H2 lặp tiền tố từ khóa (cả dạng biến thể), dòng nhắc lại tiêu đề mục/tiêu đề bài, câu dẫn meta mới ("… bắt đầu bằng nội dung này:", "Đoạn văn này đưa ra…") | lần chạy thật 6 và 7 | thấp |
| 8 | Không có cách giải phóng RAM mà không xóa cache model | audit | thấp |
| 9 | Plugin cũ: 5 trang quản trị không vào được, trường ngân sách luôn dừng campaign, giới hạn số từ không khớp, `smoke-v110.php` lỗi thời | kiểm kê | ghi nhận — **không sửa** (giữ nguyên 1.2.0 theo yêu cầu) |
| 10 | QA mới báo nhầm: câu phủ định đúng ("không phải mua xe mà là thuê") bị tính là lẫn mua/thuê; "deposits" số nhiều không được nhận là tiền cọc | lần chạy thật 9 (bài VI QA 40) | trung bình |
| 11 | Model in giá rác "000000000đ/ngày" | lần chạy thật 9 | thấp |
| 12 | Kiểm tra trùng nội dung bỏ sót bản sao khi site có nhiều bài cùng từ khóa (chỉ tìm theo từ khóa/tiêu đề) | kiểm thử tích hợp lặp lại trên CSDL >100 bài | trung bình |

## 4. Lỗi đã sửa
Đã sửa lỗi 1–8, 10, 11 (câu có số toàn 0 bị bỏ khi làm sạch, QA vẫn đánh dấu) và 12 (tìm thêm theo 2 cụm câu nguyên văn), mỗi lỗi có kiểm thử hồi quy:
- `tests/quality.php` (60 kiểm tra);
- `tests/js/core.test.mjs` (29);
- `tests/wp-integration.php` (66);
- `tests/e2e/admin-ui.e2e.cjs` (59);
- `tests/rollback.sh`.

Lỗi 9 thuộc plugin cũ, chỉ được ghi vào tài liệu.

## 5. Kiểm thử đã chạy (cục bộ, trước khi push)

| Bộ kiểm thử | Kết quả |
|---|---|
| `php -l` mọi file PHP của repo | PASS |
| `php qwen-local-writer/tests/quality.php` | 60/60 PASS |
| `php qwen-local-writer/tests/markdown.php` | 18/18 PASS |
| `node --test qwen-local-writer/tests/js/*.test.mjs` | 29/29 PASS |
| `node --check` mọi JS của plugin + `assets/admin.js` | PASS |
| `wp eval-file tests/wp-integration.php` (WP 7.1.3 + SQLite, cùng plugin cũ) | 66/66 PASS |
| `tests/e2e/admin-ui.e2e.cjs` (Chromium, model giả) | 59/59 PASS |
| `tests/rollback.sh` 0.3.0-beta ↔ 0.2.0-beta | PASS |
| `php tests/smoke-v120.php` (plugin cũ, mock) | 27/27 PASS |
| `php tests/smoke-v110.php` (plugin cũ, mock) | FAIL — lỗi thời, kiểm thử giao diện 1.1 đã bị thay; không chạy trong CI |
| `build_qwen.py` / `build_plugin.py` | PASS (ZIP Qwen 19 file, không chứa test, trọng số hay chuỗi CDN/API thương mại của plugin) |
| Chấm lại 5 bài Qwen thật + fixture bằng QA mới | vi-brief 80 (giá lệch brief, mua/thuê), en-brief 100, vi-general 100, en-general 95, browser 60 (meta, mua/thuê, trải nghiệm), fixture 55 |

Kiểm thử trên GitHub Actions (chi tiết ở mục 8):

| Kiểm thử | Kết quả |
|---|---|
| CI `97af80d` (run 39914553616): PHP 8.1/8.3 lint + unit + build; WP 6.9.10/PHP 8.1; WP 7.1.3/PHP 8.3 | PASS |
| CI cùng WooCommerce 11.2.1 + Yoast SEO 28.7 + Rank Math 1.0.280 (WP 7.1.3/PHP 8.3) | PASS — tích hợp 68/68 (nhận diện Yoast, Rank Math), E2E 59/59, nâng/hạ phiên bản, gỡ cài đặt giữ 6 bản nháp, không có PHP Fatal |
| Model thật, lần chạy 8 (`060f29b`, run 39913479875) | **PASS** — CPU: 1.432–1.669 từ, QA 80–100; trình duyệt: 1.664 từ, 17,5 phút, nháp #5, QA 80 |
| Model thật, lần chạy 9 (`97af80d`, run 39914553591) | **FAIL** ở job CPU: bài VI có brief QA 40 (giá rác "000000000đ", "Honda Vision 150.000đ/ngày" ≠ brief, 4 câu "tôi đã…", 3 câu bị báo nhầm mua/thuê). 3 bài còn lại 85–100 |

## 6. Giới hạn chưa xử lý (NOT VERIFIED / còn thiếu)
- **GPU WebGPU thật**: chưa có máy GPU. Chỉ kiểm thử WASM và SwiftShader.
- **Safari/iPhone/iPad với model thật**: chỉ kiểm tra bố cục bằng giả lập thiết bị Chromium.
- **Website đang chạy / staging thật**: chưa cài. Chỉ dùng WordPress dùng-một-lần trong CI và cục bộ.
- **Chất lượng nội dung Qwen3-0.6B**:
  - vẫn có câu chung chung hoặc vô nghĩa, và sai kiến thức ngoài các mẫu QA biết;
  - QA chỉ là đầu mối cho người kiểm chứng.
- **Tốc độ**: WASM trên CPU 4 vCPU cần 24–29 phút mỗi bài, và phải giữ tab mở.
- **Phạm vi kiểm tra trùng nội dung**: chỉ so với tối đa 40 bài tìm thấy bằng tìm kiếm WordPress (từ khóa, tiêu đề, 2 cụm câu nguyên văn), không phải toàn bộ website. Chỉ tìm theo từ khóa/tiêu đề thì bỏ sót bản sao trên site có nhiều bài cùng chủ đề (phát hiện khi chạy lại kiểm thử tích hợp trên CSDL có >100 bài) — đã bổ sung tìm cụm câu nguyên văn.
- **Worker treo không báo lỗi** (trình duyệt tắt tiến trình): không có watchdog. Cách xử lý là tải lại trang; nội dung được khôi phục (đã kiểm thử E2E).
- **Plugin cũ 1.2.0**: các lỗi ở mục 3 (#9) chưa sửa.

## 7. Khả năng sử dụng thực tế
**BETA — sẵn sàng thử trên staging, chưa sẵn sàng production.**

Plugin tạo được bản nháp ~1.500 từ VI/EN đúng cấu trúc và lưu an toàn. QA cũng chỉ ra các câu cần kiểm chứng. Tuy vậy:
- mỗi bài vẫn cần biên tập viên sửa và kiểm chứng;
- tốc độ trên máy không có GPU chậm;
- chưa có bằng chứng trên GPU thật và Safari.

Nên thử trên staging với một máy Chrome có GPU. Nếu chất lượng 0.6B không đủ, chủ website quyết định có thử model lớn hơn (ví dụ Qwen3-1.7B, chậm và nặng hơn) hay không.

## 8. Commit, CI và Pull Request
- Nhánh `feat/qwen-local-no-paid-api`, PR #1 (vẫn là bản nháp/Beta, chưa merge, không triển khai production).
- Các commit của phiên này:
  - `060f29b`: 0.3.0-beta, QA nhóm, an toàn dữ liệu, CI tương thích;
  - `8441df8`: lỗi trình bày từ lần chạy 7;
  - `97af80d`: E2E khi WooCommerce đổi hướng subscriber;
  - commit tiếp theo: sửa QA báo nhầm và số rác từ lần chạy 9.
- Lần chạy 9 FAIL là kết quả thật. Model viết một bài sai giá và bịa trải nghiệm, QA chặn đúng (dưới 70 → nháp gắn nhãn cần sửa). Ngưỡng 70 **không** bị nới. Phần báo nhầm của QA đã được sửa.
- Kết quả CI và lần chạy model thật cho commit cuối cùng được ghi trong mô tả PR #1. Vì mẫu sinh ngẫu nhiên, một lần chạy sau có thể đạt hoặc không đạt: đó là đo chất lượng model, không phải lỗi phần mềm.
