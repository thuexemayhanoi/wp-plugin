# Qwen Local Writer 0.3.0-beta — viết nháp WordPress bằng Qwen mã nguồn mở, không API trả phí

Plugin WordPress **độc lập** (thư mục `qwen-local-writer/`) dùng mô hình sinh văn bản mã nguồn mở
**Qwen3-0.6B** (bản ONNX `onnx-community/Qwen3-0.6B-ONNX`, giấy phép Apache-2.0 — *không phải* Qwen Embedding)
chạy **ngay trong trình duyệt của người quản trị**. Không Claude/Gemini/OpenAI, không dịch vụ inference tính phí,
không API key, không chạy model trên máy chủ PHP. Bài luôn được lưu ở trạng thái **Nháp**.

Plugin cũ *Multi AI SEO Content Factory 1.2.0* ở thư mục gốc repo **được giữ nguyên**, không bị xoá hay di chuyển dữ liệu.

> **Trạng thái beta.** Suy luận Qwen thật đã chạy trên GitHub Actions (CPU và Chromium/WASM); kết quả bài ~1.500 từ
> VI/EN được ghi trong báo cáo kiểm thử. **Chưa thử trên GPU WebGPU thật** và **chưa cài trên website đang chạy**.
> Thử trên staging trước.
> Qwen 0.6B là mô hình nhỏ: nội dung có thể sai, chung chung hoặc lặp ý — luôn biên tập thủ công.
> Kết quả đo thực tế: [docs/QWEN-TEST-REPORT.md](../docs/QWEN-TEST-REPORT.md).

## Cách hoạt động

1. **Kiểm tra thiết bị**: WebGPU (có GPU thật hay chỉ bộ giả lập phần mềm, có `shader-f16` không), WASM đa luồng,
   RAM trình duyệt báo cáo, dung lượng lưu trữ còn trống, cảnh báo iPhone/iPad.
2. **Tải Qwen** (khi bấm nút): trình duyệt tải trọng số model (~570 MB bản `q4f16` cho GPU có f16, ~920 MB bản `q4`
   cho GPU không f16 hoặc WASM) từ Hugging Face theo **revision cố định**
   `58ca03a5e7b354b480b1a82b34bde8dc7b854cc0`, hoặc từ thư mục tự lưu trữ trên chính website. Model được giữ trong
   Cache Storage của trình duyệt; lần sau không tải lại.
3. **Viết theo từng phần** để phù hợp giới hạn mô hình nhỏ: dàn ý 7 H2 → mở bài → 7 phần → kết luận (~1.500 từ),
   tự điều chỉnh độ dài từng phần, viết lại khi đầu ra quá ngắn hoặc lẫn chữ Hán, bổ sung phần ngắn nhất nếu thiếu chữ.
   Văn bản hiện dần trên màn hình (streaming), có thanh tiến độ, token/giây, thời gian còn lại, nút **Dừng ngay**.
4. **Chấm QA** (kỹ thuật, kiểm chứng dữ kiện, SEO) và **Lưu bản nháp**. Lưu lần hai sẽ **cập nhật đúng bản nháp đó**
   (không tạo bài trùng); có cảnh báo nếu đã có bài cùng tiêu đề; không bao giờ ghi đè bài đã chuyển khỏi trạng thái Nháp
   hoặc bài nháp của người dùng khác.
5. **Biên tập (con người)**: mở bài nháp, sửa và kiểm chứng; tích ô *Biên tập* trong hộp Qwen Local Writer. Nếu sau đó
   Qwen viết lại bài, ô này tự bỏ chọn.

Markdown gốc được lưu trong `_qlw_markdown` và hiển thị chỉ-đọc trong hộp *Qwen Local Writer — Markdown & QA* ở màn hình
sửa bài. Nội dung bài là block Gutenberg đã lọc: chỉ đoạn văn, H2–H3 (H1 tự hạ cấp vì theme đã in tiêu đề bài là H1),
danh sách, **đậm**, *nghiêng* và liên kết http(s). Mọi HTML/mã khác bị escape.

## Yêu cầu

| Thành phần | Yêu cầu |
|---|---|
| WordPress / PHP | 6.9+ / 8.1+ (CI: WP 6.9.10 + PHP 8.1, WP 7.1.3 + PHP 8.3) |
| Kết nối | HTTPS (WebGPU và Cache Storage chỉ chạy trong secure context) |
| Trình duyệt | Chrome/Edge bản mới (WebGPU). Firefox/Safari bản mới chạy chế độ WASM; Safari không hỗ trợ COEP `credentialless` nên WASM chạy một luồng (chậm hơn). Chỉ Chromium đã được kiểm thử tự động |
| Máy tính | Khuyến nghị ≥ 8 GB RAM, ~1–1,5 GB dung lượng trình duyệt trống |
| Tải lên ZIP | ~7 MB — nếu hosting giới hạn upload thấp hơn, giải nén và tải thư mục qua SFTP |

Tốc độ đo thật trên máy CI 4 vCPU (2 nhân vật lý), không GPU — chi tiết trong báo cáo kiểm thử: viết trọn bài ~1.600 từ
trong Chromium chế độ WASM mất **24–29 phút** (1,1–1,5 token/giây tính cả phần đọc prompt; 2,6 token/giây với prompt ngắn).
WebGPU giả lập phần mềm chỉ ~0,13 token/giây nên chế độ *Tự động* bỏ qua. Cùng model trên CPU gốc (Node) đạt 23–27
token/giây. GPU thật thường nhanh hơn WASM nhiều nhưng **chưa được đo** trong dự án này.

## Cài đặt và sử dụng

1. Tải `qwen-local-writer.zip` từ artifact **qwen-local-writer-beta-zip** của GitHub Actions (PR #1).
2. WordPress staging → *Plugin → Cài mới → Tải plugin lên* → kích hoạt **Qwen Local Writer**.
3. Mở menu **Qwen Local Writer** (quyền `edit_posts`: Admin, Biên tập viên, Tác giả, Cộng tác viên).
4. Bấm **Tải Qwen vào trình duyệt**, nhập tiêu đề, từ khóa, ngôn ngữ (Tiếng Việt/English), danh mục và **dữ liệu đã
   xác minh** (NAP, giá, chính sách…). Qwen chỉ được phép dùng số liệu có trong brief; QA đánh dấu mọi giá, số điện thoại,
   %, link, email không có trong brief.
   Nếu quản trị viên đã nhập *Dữ kiện đã xác minh của cửa hàng* (Cài đặt nâng cao), bấm **Chèn dữ kiện đã xác minh**
   chỉ khi bài cần thông tin cửa hàng — không nhồi vào mọi bài.
5. Bấm **Viết nháp ~1.500 từ**, giữ tab mở. Sửa Markdown → **Chấm QA** → **Lưu bản nháp**.
6. Mở bài nháp, xử lý từng câu trong mục *Câu cần kiểm chứng*, biên tập, tích ô *Biên tập* rồi mới tự xuất bản bằng tay.
7. Xong việc: **Giải phóng bộ nhớ** (giữ file model trong cache) hoặc **Xóa cache model** (xoá ~0,6–0,9 GB).

Dữ kiện mẫu cho cửa hàng (dán vào *Cài đặt nâng cao → Dữ kiện đã xác minh*, sửa khi giá thay đổi):

```text
Thương hiệu: Cho Thuê Xe Máy Nguyễn Tú
Địa chỉ: 112 Nguyễn Văn Cừ, Bồ Đề, Long Biên, Hà Nội
Điện thoại: 0942 467 674
Website: https://thuexemaynguyentu.com/
Honda Wave: 150.000đ/ngày
Honda Vision: 200.000đ/ngày
Honda Air Blade: 200.000đ/ngày
Xe tay ga 50cc: 200.000đ/ngày
Tiền cọc tùy dòng xe, xác nhận khi đặt xe. Giá tham khảo, cần xác nhận trước khi đặt.
```

Nếu thiết bị không có WebGPU, plugin báo đúng tình trạng và chạy WASM (CPU). Lỗi mạng, thiếu file model (404),
hết bộ nhớ, hết dung lượng, GPU bị ngắt, Worker bị dừng đột ngột đều có thông báo riêng; phần đã viết được giữ và tự lưu
tạm trong trình duyệt **cả trong lúc model đang viết** (tối đa 3 giây một lần), khôi phục khi tải lại trang. Nếu trình
duyệt tắt Worker mà không báo lỗi (treo), tải lại trang: bản đang viết được khôi phục.

### Tự lưu trữ model (tuỳ chọn, quản trị viên)

*Cài đặt nâng cao → Nguồn tải model → Tự lưu trữ trên website này*, rồi đặt các file của revision đã ghim vào
`wp-content/uploads/qwen-local-writer/models/onnx-community/Qwen3-0.6B-ONNX/`: `config.json`, `generation_config.json`,
`tokenizer.json`, `tokenizer_config.json`, `onnx/model_q4f16.onnx` (WebGPU f16) và/hoặc `onnx/model_q4.onnx` (WASM).
Trình duyệt khi đó không liên hệ Hugging Face. Đường dẫn chỉ chấp nhận cùng tên miền với WordPress.

## QA tự động 70/100 và bốn bước kiểm tra

Điểm bắt đầu 100, trừ theo quy tắc cố định (cùng đầu vào → cùng điểm). **Đạt 70** nghĩa là *đủ điều kiện để biên tập viên
rà soát*, **không** chứng nhận nội dung đúng, không bảo đảm SEO hay thứ hạng. Bài vẫn được lưu nháp khi chưa đạt.

| Nhóm | Kiểm tra | Trừ |
|---|---|---|
| **Kỹ thuật** | Độ dài < 50% / < 80% / > 140% mục tiêu 1.500 từ | 35 / 20 / 10 |
| | Ít hơn 4 H2 · có H1 · mỗi H2 dưới 60 từ | 20 · 15 · 5 mỗi phần (tối đa 15) |
| | Tiêu đề còn rác (`*H2:`, "7th Section:") · H2 lặp · mục trộn lẫn · câu meta ("đây là nội dung kết…:") | 10 · 10 · 15 · 15 |
| | HTML/mã nguy hiểm (script, iframe, `on…=`, `javascript:`) | 70 (và từ chối lưu) |
| | Placeholder, "as an AI…", thẻ `<think>`, câu chatbot | 25 |
| | Đoạn văn lặp nguyên văn · câu lặp > 5% (> 10%) | 20 · 8 (15) |
| | Lẫn chữ Hán/ngôn ngữ khác · sai ngôn ngữ | 10–25 · 25 |
| **Kiểm chứng dữ kiện** | Giá, số điện thoại, %, năm, link, email **không có trong brief** | 5 mỗi mục (tối đa 20) |
| | Giá gắn với một mẫu xe **khác giá của mẫu đó trong brief** (ví dụ Vision 150.000đ khi brief ghi 200.000đ) | 15 mỗi câu (tối đa 30) |
| | Xe xăng (Wave, Vision, Air Blade, 50cc…) bị gọi là xe điện | 10 mỗi câu (tối đa 20) |
| | Địa lý Hà Nội nghi sai ("thị trấn Long Biên", Hà Nội + Tây Nguyên/Central Highlands) | 10 mỗi câu (tối đa 20) |
| | Bài về **thuê** xe nhưng nói mua/bán xe ("người mua", "mua xe") | 5 (≥ 3 câu: 10) |
| | Hứa "không cần đặt cọc" khi brief không xác nhận | 15 |
| | Khẳng định không kiểm chứng (nổi tiếng, hàng đầu, số 1, thành lập năm…, X năm kinh nghiệm) | 5 mỗi câu (tối đa 10) |
| | Đánh giá/số sao của khách hàng · dẫn "nghiên cứu/thống kê" không nguồn · kể trải nghiệm cá nhân | 10 · 10 · 10 |
| **SEO** | Từ khóa không xuất hiện / nhồi > 3% · H2 lặp từ khóa hoặc cùng tiền tố | 5 / 10 · 5 |
| | Nội dung trùng ≥ 30% (≥ 50%) với bài đã có · tiêu đề gần trùng bài đã có | 10 (20) · 5 |
| | Tiêu đề ngoài 20–70 ký tự · tiêu đề không chứa từ khóa | chỉ lưu ý |
| **Biên tập** | Người biên tập tích ô xác nhận trong trình soạn bài (lưu người và thời gian) | không tính điểm |

Mục *Câu cần kiểm chứng* liệt kê nguyên câu bị đánh dấu. Không có cảnh báo **không** có nghĩa là bài đúng: QA chỉ
bắt được những mẫu sai đã biết và các con số không có trong brief.

## Bảo mật và dữ liệu

- Mọi AJAX (`qlw_save_draft`, `qlw_qa_preview`) cần đăng nhập, quyền `edit_posts` và nonce; cài đặt cần `manage_options`
  và nonce. Gán danh mục kiểm tra `assign_term`; cập nhật bài kiểm tra `edit_post`, chỉ với bài nháp do plugin tạo
  **và thuộc chính người dùng đó**. Ô xác nhận biên tập cần nonce riêng của bài và quyền `edit_post`.
- Khi Yoast SEO hoặc Rank Math đang bật, plugin điền *từ khóa chính* của plugin SEO cho bản nháp nó tạo (chỉ khi ô đó
  còn trống; không bao giờ ghi đè). Không đụng tới permalink, canonical hay meta của bài cũ.
- Không xuất bản, không xoá bài, không sửa bài khác. Gỡ cài đặt chỉ xoá `qlw_settings`, `qlw_version`; bài và meta giữ nguyên.
- Thư viện chạy model (Transformers.js 4.3.1, ONNX Runtime Web 1.31.0-dev.20260914) được **đóng gói trong ZIP**, không tải
  từ CDN. File không được commit vào git (secret scanning của GitHub báo nhầm chuỗi trong bản minified) mà được
  `scripts/vendor_qwen_deps.py` lấy từ registry npm, kiểm tra sha512 của gói và sha256 từng file theo
  `assets/vendor/manifest.json`. Giấy phép: Apache-2.0 và MIT, kèm trong ZIP.
- Chỉ trang *Qwen Local Writer* trong wp-admin gửi `Cross-Origin-Opener-Policy: same-origin` và
  `Cross-Origin-Embedder-Policy: credentialless` để WASM chạy đa luồng; có thể tắt trong Cài đặt nâng cao hoặc filter
  `qlw_cross_origin_isolation`. Trang công khai không tải bất kỳ file nào của plugin.
- Văn bản chỉ rời trình duyệt khi bấm *Chấm QA* hoặc *Lưu*, và chỉ gửi tới `admin-ajax.php` của chính website.
- Meta `_qlw_generation` (thiết bị, dtype, số token…) do trình duyệt tự khai báo; không phải bằng chứng nội dung do Qwen viết.

## Giới hạn đã biết

- Qwen3-0.6B viết tiếng Việt/tiếng Anh trôi chảy vừa phải nhưng hay chung chung, đôi khi sai kiến thức (địa danh, loại
  xe, mua/thuê), có thể lặp ý hoặc lệch chủ đề. QA chỉ đánh dấu các mẫu sai đã biết; các sai sót khác phải do người
  biên tập phát hiện.
- Kiểm tra trùng nội dung chỉ so với tối đa 40 bài tìm được bằng tìm kiếm WordPress (từ khóa, tiêu đề và 2 cụm câu nguyên văn của bài), không phải toàn
  bộ website.
- Cần giữ tab mở; không có chạy nền phía máy chủ, không có viết hàng loạt/Excel trong plugin này.
- iPhone/iPad: giao diện dùng được (đã kiểm tra bố cục), nhưng Safari có thể đóng tab khi tải model ~0,6–0,9 GB.
- Kiểm tra trùng tiêu đề không phân biệt hoa/thường trên MySQL; trên CSDL có `LOWER()` chỉ ASCII (SQLite) vẫn bắt được
  biến thể hoa/thường của các bài do plugin tạo.

## Phát triển và kiểm thử

```bash
python3 scripts/vendor_qwen_deps.py            # lấy runtime từ npm, kiểm tra hash
php qwen-local-writer/tests/quality.php        # QA (69 kiểm tra, dựng từ lỗi thật của Qwen)
php qwen-local-writer/tests/markdown.php       # Markdown → block an toàn (18)
node --test qwen-local-writer/tests/js/*.test.mjs   # điều phối bài viết + Worker + lỗi thật của Qwen (29)
python3 scripts/build_qwen.py                  # dist/qwen-local-writer.zip
wp eval-file qwen-local-writer/tests/wp-integration.php   # trên WordPress dùng-một-lần (66)
WP_URL=… WP_CLI="wp --path=…" node qwen-local-writer/tests/e2e/admin-ui.e2e.cjs   # Chromium, model giả (59)
WP_CLI="wp --path=…" qwen-local-writer/tests/rollback.sh dist/qwen-local-writer.zip ban-truoc.zip   # nâng/hạ phiên bản
```

`tests/e2e/fake-transformers.mjs` là **bản giả lập chỉ dùng để kiểm thử giao diện**, không chứng minh chất lượng Qwen.
Suy luận thật chạy trong workflow `.github/workflows/qwen-real-inference.yml` (cần internet tới huggingface.co).

## Rollback

Huỷ kích hoạt hoặc gỡ plugin: không ảnh hưởng plugin cũ, bài viết và dữ liệu khác. Các bài nháp đã tạo vẫn còn và
có thể xoá thủ công. Quay về bản trước: *Plugin → Cài mới → Tải plugin lên* ZIP bản cũ → *Thay thế bản hiện tại*;
bài nháp, meta và cài đặt giữ nguyên (đã kiểm thử 0.3.0-beta ↔ 0.2.0-beta bằng `tests/rollback.sh`). Kiến trúc: [docs/superpowers/specs/2026-10-23-qwen-local-writer-design.md](../docs/superpowers/specs/2026-10-23-qwen-local-writer-design.md).
