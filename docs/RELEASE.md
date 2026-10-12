# Release and deployment

1. Update version in plugin PHP header and `MASF_VERSION`, README, readme.txt and CHANGELOG.
2. Run CI PHP lint + smoke tests and inspect changed code. Avoid using real API keys in CI.
3. Build `python scripts/build_plugin.py` and verify the ZIP has **exactly one top-level** `multi-ai-seo-content-factory/` directory; verify entry PHP is inside.
4. GitHub Actions publishes ZIP as a **workflow artifact** after successful CI; download artifact, then extract `multi-ai-seo-content-factory.zip` before installing in WordPress.
   On a push of a `vX.Y.Z` tag matching the PHP plugin Version, the same CI must pass first; then the `github-release` job uploads the ZIP as a GitHub Release asset. No tag is created automatically. Never tag an untested change.
5. **Manual staging QA**: database backup; test activation, upgrade from v1.0.0, provider key, mocked and then authorized real API test, Excel import, 1–5 Drafts, retries, pause/resume, billing failure, token cap and Matrix. Observe WP-Cron/Action Scheduler. Repeat on mobile.
6. Only after manual approval update WordPress production. GitHub commit/Actions artifact **does not deploy automatically**.

Do not put `.env`, billing credentials, database dumps or wp-config.php into artifacts. Preserve licenses of bundled third-party components.
