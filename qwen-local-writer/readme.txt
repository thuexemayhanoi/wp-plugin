=== Qwen Local Writer ===
Contributors: thuexemayhanoi
Tags: ai, local, qwen, content, draft
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.3.0-beta
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Write ~1,500-word WordPress drafts with the open-source Qwen3-0.6B model running in the administrator's browser. No paid AI API, no API key.

== Description ==

* Model: onnx-community/Qwen3-0.6B-ONNX (Apache-2.0), downloaded on demand into the admin's browser cache (Hugging Face, or a self-hosted copy on the same site). Weights are not in the ZIP.
* Runtime: Transformers.js 4.3.1 + ONNX Runtime Web, bundled locally in assets/vendor (no CDN). WebGPU when available, WASM (CPU) fallback.
* Writes in sections (outline, intro, 7 H2 sections, conclusion) in Vietnamese or English; Markdown is converted to safe H2/H3/paragraph/list blocks.
* Always saves a Draft. Never publishes, never edits a post that left Draft status.
* Deterministic QA (threshold 70/100) in groups: technical (length, H2 structure, H1, repetition, wrong language/Chinese characters, unsafe code), factual flags (prices/phones/years/links not in the brief, a model price that contradicts the brief, petrol bikes called electric, wrong Hanoi geography, "buy" in a rental article, invented reviews, sources or experience) and SEO (keyword use and stuffing, title length, near-duplicate posts). Flags are leads for a human fact-checker; the score never certifies facts.
* Editorial review is a human step: an editor ticks the sign-off box in the post editor.
* Optional verified business facts (admin setting) that writers can insert into a brief.
* Sets the focus keyword of Yoast SEO / Rank Math on its own new drafts when one of them is active (never overwrites).
* Nothing runs on the public site. Inference never runs on the PHP server.

Qwen 0.6B is a small model: output needs human editing. Test on staging first.

== Changelog ==

= 0.3.0-beta =
* QA split into technical / factual / SEO groups plus a human editorial sign-off; fact flags from real Qwen mistakes; brief price-per-model check; near-duplicate post detection.
* Never overwrites another user's draft; editorial sign-off resets when Qwen rewrites the draft.
* Verified business facts setting, "free memory" button, recovery saved while text streams, clear message when the Worker crashes.
* Yoast SEO / Rank Math focus keyword on new drafts; version kept in step when the ZIP is replaced (upgrade/rollback).

= 0.2.0-beta =
* Vendored Transformers.js/ONNX Runtime (no CDN), WebGPU + WASM fallback, streaming and immediate stop.
* Duplicate-title check, draft update instead of duplicate drafts, recovery in browser storage.
* Expanded technical QA, block markup output, metabox with Markdown and QA report, responsive UI.

= 0.1.0-beta =
* First prototype.
