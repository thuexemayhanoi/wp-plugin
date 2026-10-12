# Nguồn nghiên cứu và quyết định

Đọc trực tiếp main của `https://github.com/thuexemayhanoi/web` tại HEAD `39e939cf85658f82971c9c346d265b6d361fc780`: `README.md`, `factory/WRITER.md`, `tools/factory.mjs`, `data/factory-config.json`, `data/factory-state.json`, `data/writer-queue.json`, `data/content-matrix.csv`, `.github/workflows/article-factory.yml`. Root `AGENTS.md` trả 404 ở snapshot đọc; không suy diễn là đã đọc file này. Không sửa repo hoặc chạy workflow.

Tài liệu chính thức được đối chiếu:

- https://platform.claude.com/docs/en/api/messages/create — Messages request, system, max_tokens, content blocks, version header.
- https://platform.claude.com/docs/en/api/models/list — model catalog.
- https://ai.google.dev/api/models — catalog, supportedGenerationMethods.
- https://ai.google.dev/api/generate-content — generateContent, systemInstruction, generationConfig, usage/finishReason.
- https://developers.openai.com/api/reference/resources/responses/methods/create — Responses input, output, JSON formatting, usage, store.
- https://developers.openai.com/api/reference/resources/models — Models catalog.
- https://actionscheduler.org/usage/ và https://actionscheduler.org/api/ — load trước plugins_loaded, dùng API sau initialization, recurring actions, unique scheduling và version negotiation.
- https://github.com/woocommerce/action-scheduler/releases/tag/4.2.0 — thư viện đóng gói từ bản phân phối WordPress.org 4.2.0, giữ license/source.
- https://developer.yoast.com/customization/apis/metadata-api/ — để Yoast quản lý meta output; source `WPSEO_Meta` 28.6 được kiểm thử bằng plugin thật.
- https://developer.wordpress.org/reference/functions/wp_insert_post/ và https://developer.wordpress.org/reference/functions/wp_update_post/ — storage/publishing; source WordPress 7.1.3 được đọc để sửa draft date scheduling.

Model ID không được lấy từ ví dụ tài liệu làm giá trị mặc định. Catalog và Test Connection dùng API của chính tài khoản quản trị sau khi cài.

Thiết kế WordPress dùng bảng DB và post APIs, không chuyển mã Node thành đoạn PHP chạy shell. Mỗi bước có checkpoint/lease; một provider gọi cho writing hoặc repair, reviewer riêng nếu chọn. Brand Profile trắng, không có dữ liệu cửa hàng điền sẵn.
