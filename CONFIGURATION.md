# Cấu hình API và chiến dịch

## Ba nhà cung cấp

| Provider | Nơi quản trị khóa | API dùng |
|---|---|---|
| Claude | https://platform.claude.com/ hoặc Anthropic Console của tài khoản | Anthropic Messages `/v1/messages` |
| Gemini | https://aistudio.google.com/apikey | Gemini `generateContent` |
| OpenAI | https://platform.openai.com/api-keys | OpenAI Responses `/v1/responses` |

Tài khoản thuê bao ChatGPT/Claude/Gemini không tự thay thế tài khoản/hạn mức API.

Với mỗi provider:

1. Mở **AI Content Factory → API Providers**.
2. Dán key vào **API key mới** rồi lưu. Ô key không hiển thị lại; để trống lần sau giữ key cũ.
3. Bấm **Lấy danh sách model**. Danh sách chỉ là catalog hiện tại của API.
4. Nhập/chọn **Model ID mặc định**, lưu. Có thể nhập ID mới nếu catalog chưa chứa hoặc không liệt kê quyền riêng.
5. Bấm **Test Connection**. Đây là một inference request nhỏ, có thể tính phí. Thành công xác nhận quyền gọi model đã chọn; không xác nhận số dư tiền API.
6. Chọn output token, timeout, request/phút, token/ngày và giá token phù hợp model/hạn mức tài khoản.

Lỗi 401: key sai/đã thu hồi. 403: tài khoản hoặc model không có quyền. 404: model không tồn tại. 400: model không hỗ trợ định dạng/endpoint hoặc tham số. 429: rate limit hoặc quota. Plugin không đưa nội dung lỗi thô chứa dữ liệu nhạy cảm ra logs.

OpenAI catalog có cả model audio/image/embedding hoặc model không hỗ trợ Responses JSON. Chọn model tạo văn bản có hỗ trợ Responses/JSON mode rồi Test Connection. Model ID riêng ở chiến dịch dùng cấu hình token/timeout/đơn giá của provider đó; kiểm tra lại đơn giá khi dùng model khác.

Có thể dùng constant `MASF_CLAUDE_API_KEY`, `MASF_GEMINI_API_KEY`, `MASF_OPENAI_API_KEY` trong cấu hình máy chủ thay cho DB. Constant có ưu tiên hơn key nhập admin. Bản ZIP không chứa key thật hoặc tự đọc key của plugin khác.

## Site / Brand Profile

Nhập tên thương hiệu, địa chỉ, điện thoại, website, Maps, giờ mở cửa, bảng giá, đặt cọc, nội dung được phép và nội dung không được suy đoán. Không chép NAP của repo tham chiếu sang website. Plugin không tự lấy thương hiệu từ tiêu đề website hay profile người dùng.

Giá phải ghi rõ đơn vị/kỳ thuê và mức đã xác nhận; gói chưa công bố ghi cần liên hệ/xác nhận. Nguồn trong chiến dịch nên gồm **URL + trích đoạn hoặc dữ liệu thực tế**. Câu trả lời AI không trở thành nguồn xác minh chỉ vì AI khai báo `claims`.

## Chiến dịch đầu tiên: 5 bài

1. Chọn **Chiến dịch viết bài**, đặt tên, ngôn ngữ Việt/English, loại bài và danh mục cha/con đã có.
2. Chọn writer và model. Để fallback/reviewer trống nếu muốn chỉ dùng một AI. Chọn AI khác làm reviewer/repair khi cần.
3. Chọn 1.000–2.000 từ, Draft, lô 5, tối đa 5 bài/ngày, một worker, QA 75, sửa tối đa 2 lần.
4. Nhập brief và nguồn. Internal links dùng ID bài/trang đã publish trên **website này**; Matrix detail cho biết mục tiêu.
5. Lưu chiến dịch ở trạng thái Pause. Nhập mẫu Excel/CSV hoặc dán tiêu đề/từ khóa theo dòng.
6. Bấm **Process Next Batch**. Khi 5 bài đã giao hoặc chạm trạng thái lỗi cuối, chiến dịch tự Pause. Bài thứ 6 chưa được viết.
7. Mở Matrix để xem post ID và QA; bấm **Mở bản nháp / bài WordPress** để đọc. Nếu cần chỉnh nội dung cho một bài còn trong REPAIR, nên dừng factory; vòng sửa tiếp theo sẽ viết lại bản nháp do factory sở hữu.

Độ dài được kiểm tra theo đơn vị tách từ: Việt dùng các cụm chữ/số tách khoảng trắng, không khẳng định là số từ ghép ngôn ngữ học. English dùng lexical units; zh/ja dùng đơn vị ký tự CJK và các từ khác. Ngôn ngữ mở rộng còn cần thử chất lượng thực tế với model chọn.

## Mở rộng 20, 100, 300 bài

Lô hỗ trợ 1/5/10/20. Upload 100/300/500 dòng vào Matrix không tạo hàng trăm request song song. Mỗi file tối đa 10.000 dòng/5 MB; có thể nhập nhiều file liên tiếp. Mặc định global concurrency 1; mỗi chiến dịch còn có giới hạn riêng 1–3. Bản đầu xử lý tuần tự trong từng chiến dịch để giữ giới hạn ngày/batch; có thể chạy nhiều chiến dịch trong giới hạn global.

Tăng lên lô 20 sau khi đọc 5 Draft đạt yêu cầu; đặt số bài/ngày và ngân sách theo thực tế. **Start/Resume** chạy liên tục qua các lô cho tới hết phần queue hợp lệ, Pause/Stop, giới hạn ngày hoặc lỗi cần xử lý. **Process Next Batch** chỉ giao tối đa một lô.

## Giới hạn API và ngân sách

- Token/ngày tính theo UTC; bài/ngày tính theo múi giờ WordPress.
- Plugin dự phòng input token bằng số byte UTF-8 và output token tối đa trước khi gửi request. Đây là cách ước tính bảo thủ, không phải tokenizer của model.
- Chi phí input/output theo USD/triệu token do quản trị viên nhập; để 0 nghĩa là chưa ước tính. Muốn đặt ngân sách >0 phải nhập cả hai đơn giá >0.
- Timeout/gián đoạn không có số usage thì giữ tiền/token dự phòng, tránh dùng quá ngân sách khi nhà cung cấp có thể đã xử lý request.
- Hóa đơn thật có thể khác do giá model, token reasoning, discount/caching, thuế và cách tính của API. Giới hạn này là kiểm soát cục bộ dựa trên cấu hình.
- Khi token/ngân sách bị chặn, bài giữ checkpoint và báo lỗi. Điều chỉnh hạn mức hoặc đợi ngày sau rồi **Retry Failed → Resume**. Bản đầu không tự sửa số dư hay mua quota.
