# QuickCall Connect

A lightweight WordPress contact-launcher plugin: Call, Zalo and Map buttons plus optional social, messaging and chatbot links. Vanilla JS, plain CSS, bundled SVG icons, no trackers.

- Plugin slug / text domain: `quickcall-connect`
- Development version: `0.1.0`
- License: GPL-2.0-or-later

## Repository layout

The repository root **is** the plugin (WordPress.org convention). The installable ZIP is the repo content without the dev-only files (`tests/`, `.github/`).

## Development

```bash
# Lint all PHP files
find . -name '*.php' -not -path './tests/*' -exec php -l {} \;

# Run behavior tests (uses tests/wp-stubs.php, no WordPress install needed)
php tests/run-tests.php
```

CI runs the same checks on every push (`.github/workflows/ci.yml`).

## Packaging

Create `quickcall-connect-<version>.zip` containing a single top-level `quickcall-connect/` folder. Exclude `.git`, `.github`, `tests/`, caches, logs and local environment files.

## Notes

- All settings live in one option: `quickcall_connect_settings`. No custom DB table.
- Deactivation keeps settings; deleting the plugin removes them (`uninstall.php`).
- Frontend assets are only enqueued when the widget is enabled and at least one channel has a valid value.
