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

## 2. Bài ~1.500 từ VI/EN qua mã điều phối của plugin

Đang chạy trong workflow `Qwen real inference` — kết quả sẽ được cập nhật tại đây.

## 3. Kiểm thử tự động (không dùng model)

| Bộ kiểm thử | Kết quả cục bộ |
|---|---|
| `php tests/quality.php` (QA) | 23/23 |
| `php tests/markdown.php` (Markdown → block) | 18/18 |
| `node --test tests/js/*.test.mjs` (điều phối, Worker) | 19/19 |
| `wp eval-file tests/wp-integration.php` (WP 7.1.3 + SQLite, cùng plugin cũ 1.2.0) | 49/49 |
| `tests/e2e/admin-ui.e2e.cjs` (Chromium, model giả, cùng plugin cũ) | 45/45 |
| Smoke plugin cũ `tests/smoke-v120.php` | 27/27 |
