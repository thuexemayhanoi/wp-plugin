# Qwen Local Writer — migration design (2026-10-23, updated for 0.3.0-beta)

## Problem
The existing Multi AI SEO Content Factory 1.2.0 relies on commercial Claude, Gemini and OpenAI APIs to write articles. The site owner wants **no commercial API**, a ~1,500-word Vietnamese/English draft, and a minimum **technical QA target of 70/100**, while keeping existing WordPress posts and data intact.

## Scope and rationale
This is an **architectural change**, not a provider-name swap: PHP shared hosting cannot run Qwen3 generation inside WordPress requests. An embedding-only model cannot write text. Qwen3-0.6B text-generation ONNX with Transformers.js runs in the administrator's browser (WebGPU, or WASM on CPU). Word count is an editorial target, not a guarantee. Technical QA 70 is not factual verification.

## Approaches evaluated
1. PHP invokes Qwen directly on shared hosting — rejected (memory/runtime, no verified model environment).
2. Silent remote service — rejected (violates no-API intent, privacy and costs).
3. **Selected:** browser-local Qwen3-0.6B ONNX in a Web Worker; explicit device check and download; staged section generation; WordPress AJAX to save drafts; deterministic QA score and human review. The tab must stay open; no server-side background AI.

## Architecture (0.3.0-beta)
- `assets/qlw-core.js` — DOM-free logic shared by the browser and the Node real-model test: prompts (VI/EN, brief treated as data), outline parsing, output cleanup, `writeArticle()` orchestration (outline → intro → 7 H2 → conclusion, adaptive lengths, retry, top-up), sampling clamps, error classification.
- `assets/qwen-worker.js` — module Worker: WebGPU adapter/`shader-f16` probe, device plan (real GPU q4f16/q4 → WASM q4; software adapters skipped in auto mode), model allowlist + pinned revision, same-origin self-hosting, streaming, interrupt, cache clearing. Bootstrapped from a `blob:` URL so it inherits cross-origin isolation.
- `assets/local-writer.js` — admin UI only (never on the public site).
- `assets/vendor/` — Transformers.js 4.3.1 and the exact ONNX Runtime Web build it embeds; fetched from npm at build time, verified against `manifest.json`, shipped in the ZIP, never loaded from a CDN.
- `includes/class-ajax.php` — `qlw_save_draft`, `qlw_qa_preview` (capability + nonce), duplicate guard, draft-only insert/update of the user's own drafts, near-duplicate search against existing posts, Yoast SEO / Rank Math focus keyword on new drafts.
- `includes/class-markdown.php` — Markdown → core/heading (H2–H3), core/paragraph, core/list blocks; all other markup escaped; `wp_kses_post`.
- `includes/class-quality.php` — deterministic QA in groups: technical, factual flags (specifics not in the brief, brief price-per-model contradictions, claim patterns seen in real Qwen output) and SEO; editorial review stays human (metabox sign-off).
- `includes/class-plugin.php`, `includes/view-admin.php` — menu, COOP/COEP on the writer screen only, settings (model source, isolation, verified business facts), metabox with QA groups and the editorial sign-off, version upgrade on ZIP replacement.

## Repo and data safety
- Only thuexemayhanoi/wp-plugin, feature branch; no live WordPress changes.
- Legacy v1.2 files and database untouched. The plugin does not call existing API providers or read their stored keys.
- No automatic publication, no rewriting of other posts, no deletion of campaigns. Uninstall removes only `qlw_settings`/`qlw_version`. No model weights in the ZIP.
- Do **not** merge to main or replace the installed plugin until the owner explicitly approves deployment.

## Release gate and status
| Gate | Status |
|---|---|
| CI PHP syntax, unit tests, ZIP validation | automated (`ci.yml`) |
| ZIP activates on a clean WordPress (6.9.10/PHP 8.1, 7.1.3/PHP 8.3, 7.1.3 + WooCommerce/Yoast SEO/Rank Math), integration + browser E2E, upgrade/rollback, uninstall keeps posts | automated (`ci.yml`, job `qwen-wordpress`) |
| Real model download and inference, VI + EN, measured words and QA | automated (`qwen-real-inference.yml`); results in `docs/QWEN-TEST-REPORT.md` |
| No commercial API call, no CDN | build-time scan + E2E network assertions |
| Real WebGPU GPU, iPhone/iPad Safari with the real model, live staging site | **not verified** — needs the owner's hardware/staging |
| Production merge/deploy | **not authorised** |
