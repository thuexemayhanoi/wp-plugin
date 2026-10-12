# Changelog

## 1.1.0 — 2026-10-12

- Added one-page **Viết bài nhanh**: provider/key/model, count 1–100, category, target 1,000–5,000 words, language, selectable 4k–64k output tokens, titles or Excel/CSV, queue start and Draft-only default.
- API key status and local show/hide typed key; previously saved key remains server-side only.
- Provider-specific quick model persistence without changing other campaign model choices. Per-campaign token output limit instead of globally overriding existing campaigns.
- Classify provider HTTP errors: rate limit vs insufficient credits/spending quota; prevent cascading failures by pausing campaign on non-retryable billing/auth/model errors; clearer recent FAILED diagnostics.
- Increased advanced provider output-token configuration ceiling from 32,000 to 64,000 (model-dependent).
- No change to table schema, post URLs, generated posts or existing campaign checkpoint logic; preserved existing advanced menus.

## 1.0.0

Initial engine, scheduler, Matrix, QA, provider adapters and CSV/XLSX import.

### Repository transition (2026-10-12)

- Move editable Multi AI SEO Content Factory v1.1.0 into `thuexemayhanoi/wp-plugin`, retaining prior QuickCall code through backup branch and Git history.
- Add Action workflow for PHP lint, 23 mock smoke checks, deployable ZIP artifact, and GitHub Releases for matching `vX.Y.Z` tags.
- Exclude development documents, scripts and tests from installation ZIP while preserving editable source in repository.
- No changes to live WordPress, paid APIs or existing website content.
