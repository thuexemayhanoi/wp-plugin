# Qwen Local Writer — migration design (2026-10-23)

## Problem
The existing Multi AI SEO Content Factory 1.2.0 relies on commercial Claude, Gemini and OpenAI APIs to write articles. The site owner wants **no commercial API**, a ~1,500-word Vietnamese/English draft, and a minimum **technical QA target of 70/100**, while keeping existing WordPress posts and data intact.

## Scope and rationale
This is an **architectural change**, not a provider-name swap: PHP shared hosting cannot directly run Qwen3 generation in typical WordPress requests. An embedding-only model cannot write text. Qwen3-0.6B text-generation ONNX with Transformers.js can be tested on an administrator's WebGPU-enabled desktop browser; a larger local model can be a separate user-controlled offline worker in a later implementation, but never a paid inference provider. Word count is an editorial target, not a guarantee. Technical QA 70 is not factual verification.

## Approaches evaluated
1. PHP invokes Qwen directly on shared hosting — rejected (high memory/runtime and no verified model environment).
2. Silent remote service — rejected (violates no-API intent, privacy and costs).
3. **Selected for initial prototype:** browser-local Qwen3-0.6B ONNX; explicit WebGPU compatibility and download; staged section generation; WordPress AJAX to save drafts; deterministic QA score and human review. Browser tab must remain open during generation. No server-side background AI is claimed.

## Repo and data safety
- Only thuexemayhanoi/wp-plugin, feature branch; no live WordPress changes.
- Keep legacy v1.2 files and database intact during migration. A standalone qwen-local-writer/ plugin prototype is built beside the legacy files to avoid breaking running campaigns. It does not call existing API providers or share their stored keys.
- Do **not** merge to main or replace the installed plugin until install/QA/model tests pass and the owner explicitly approves deployment.
- No automatic publication, rewriting old posts, or deleting campaigns. No model weights in ZIP.
- Any remote CDN library loaded into WordPress Admin is a temporary beta dependency and must be self-hosted/pinned with verified integrity before production release.

## Minimum delivery
- Separate installable Qwen Local Writer ZIP.
- Admin screen with title, verified facts/brief, language, category, on-demand model download, progress, cancel, word count, Markdown preview, save-as-draft.
- Text generation in sections, no H1. The post title becomes H1 via theme. No invented business facts, sources or links.
- Server-side QA scoring, including word count, H2 coverage, repeated text, placeholders and unsafe content; 70 threshold results in review warning not auto-publication.
- WordPress authentication, nonce and post edit capability, strict size bounds, WordPress sanitization and post ID/edit link readback.
- Tests of QA and saved-draft security, documentation, separate packaging; actual WebGPU inference must be marked unverified until tested on suitable hardware.

## Release gate
CI PHP syntax and deterministic tests must pass; ZIP must activate in disposable WordPress; model download/inference must be witnessed and evaluated on real VI/EN prompts; no commercial API call may occur; 1,500-word generation and QA 70 outcomes measured on a sample, not promised. Fail safe if WebGPU unavailable. No production merge until reviewed.
