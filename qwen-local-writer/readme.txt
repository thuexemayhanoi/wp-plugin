=== Qwen Local Writer ===
Contributors: thuexemayhanoi
Tags: ai, local, qwen, content, draft
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0-beta
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Write ~1,500-word WordPress drafts with the open-source Qwen3-0.6B model running in the administrator's browser. No paid AI API, no API key.

== Description ==

* Model: onnx-community/Qwen3-0.6B-ONNX (Apache-2.0), downloaded on demand into the admin's browser cache (Hugging Face, or a self-hosted copy on the same site). Weights are not in the ZIP.
* Runtime: Transformers.js 4.3.1 + ONNX Runtime Web, bundled locally in assets/vendor (no CDN). WebGPU when available, WASM (CPU) fallback.
* Writes in sections (outline, intro, 7 H2 sections, conclusion) in Vietnamese or English; Markdown is converted to safe H2/H3/paragraph/list blocks.
* Always saves a Draft. Never publishes, never edits a post that left Draft status.
* Deterministic technical QA (threshold 70/100): length, H2 structure, H1, repetition, wrong language/Chinese characters, prices/phones/links not present in the brief, unsafe code. QA is not fact-checking.
* Nothing runs on the public site. Inference never runs on the PHP server.

Qwen 0.6B is a small model: output needs human editing. Test on staging first.

== Changelog ==

= 0.2.0-beta =
* Vendored Transformers.js/ONNX Runtime (no CDN), WebGPU + WASM fallback, streaming and immediate stop.
* Duplicate-title check, draft update instead of duplicate drafts, recovery in browser storage.
* Expanded technical QA, block markup output, metabox with Markdown and QA report, responsive UI.

= 0.1.0-beta =
* First prototype.
