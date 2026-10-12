# AGENTS.md — Rules for AI coding agents

## Scope and goal

- This repo `thuexemayhanoi/wp-plugin` is **only** for Multi AI SEO Content Factory. It is **not** the old QuickCall Connect chatbot.
- Keep the WordPress plugin **root entry** `multi-ai-seo-content-factory.php` and package folder slug `multi-ai-seo-content-factory/` stable.
- Prefer Vietnamese admin copy; docs can be in Vietnamese/English. Target an uncomplicated workflow: API → model → 1–100 articles → WP category → titles/Excel → 1,000–5,000 words → draft queue.
- Prioritize secure credential handling, predictable spending and job idempotency over throughput. Output token value is only a cap, not a promise of word count.

## Required safety invariants

1. Never hardcode, export or log API keys, WordPress auth cookies, WP salts, secrets or real user content in tests/commits.
2. Maintain `current_user_can`, WordPress nonce/REST permission checks, escaping, sanitization and `$wpdb->prepare` across all admin/DB flows.
3. Default to **Draft**. Do not allow a queue restart, retry or worker claim to create duplicate posts. Preserve existing Matrix rows, checkpoints, drafts and provider settings across upgrades.
4. Distinguish rate-limit/transient errors from exhausted credit, invalid credentials, billing/spend caps and wrong model. Do not misrepresent API billing balance as measurable from a content request.
5. Do not assume any live model ID, token capacity, billing price or provider endpoint compatibility without verifying at the time of the change. Avoid guessing users' business facts.
6. `vendor/action-scheduler` is third-party licensed code: do not casually modify it or rewrite its AGENTS/CLAUDE docs. Keep its license.
7. No production or paid API calls unless expressly authorized. Never silently change theme, posts, DNS, redirects, Yoast settings or other repos.

## Workflow for each change

- Read `README.md`, `QUICK-START-VI.md`, `WORKFLOW.md`, `docs/DEVELOPMENT.md` first.
- Make small changes to shared PHP classes/templates; do not fork UI logic among screens.
- Run `php tests/smoke-v110.php`, PHP syntax checks, JS syntax checks if Node is available, then `python scripts/build_plugin.py` and check ZIP root/contents. If you cannot run a test, disclose that.
- Update `CHANGELOG.md`, docs and tests when behavior changes; report version, commit, QA pass/fail, known limitations.
- Use a branch/PR for risky changes; avoid overwriting the default branch without explicit permission. Do not rewrite Git history to remove the old chatbot; history is useful for rollback.

## Packaging

- CI builds a single-root ZIP: `multi-ai-seo-content-factory/multi-ai-seo-content-factory.php`.
- Include bundled Action Scheduler and production assets; exclude `.git`, `.github`, scripts, tests, AGENTS.md, development-only docs and secrets from installable ZIP.
- Repo root is the source; packaged ZIP **must not** be unpacked as loose files into WordPress plugin directory.
