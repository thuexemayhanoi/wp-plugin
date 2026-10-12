# Developer guide

## Repo layout

The repository **root** holds the WordPress plugin code. `multi-ai-seo-content-factory.php` is the bootstrap. Source classes are under `includes/`, admin assets under `assets/`, and Action Scheduler 4.2.0 is vendored under `vendor/action-scheduler/`.

## Requirements

For source checks: PHP 8.1+, Python 3.10+; Node is optional for JS lint. For WordPress: WordPress 6.9+, MySQL/MariaDB, mbstring/dom/openssl; Zip + SimpleXML for .xlsx.

## Checks

```bash
php tests/smoke-v110.php
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/admin.js
python scripts/build_plugin.py
python -m zipfile -t dist/multi-ai-seo-content-factory.zip
```

Smoke tests use mocks and **do not** prove live API/provider, credential, WordPress DB or 100-article behavior. For paid staging use a disposable site, approved paid key, low caps and Draft mode.

## Internal controls

- Quick form routes through existing campaign and queue logic. Avoid a parallel generation engine that bypasses reservation/rate limiting/QA.
- Preserve stable DB names (`masf_*`), state transitions and WordPress post IDs on upgrade.
- Review any change to class-adapter, -queue, -engine, -admin, -providers for credential exposure, idempotence and retries.
- Check exact 402/429 response codes and error body. A 429 can indicate rate limit or insufficient credits; do not retry forever if account action is required.
- Keep docs honest about billing: content API responses do not provide balance in USD by default.
