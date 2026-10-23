#!/usr/bin/env bash
# Upgrade / rollback test on a DISPOSABLE WordPress: replace the plugin with the previous
# release ZIP and back again; drafts, Qwen meta, settings and the legacy plugin must survive.
#   WP_CLI="wp --path=/tmp/wordpress" qwen-local-writer/tests/rollback.sh current.zip previous.zip
set -euo pipefail
WP=${WP_CLI:?set WP_CLI, e.g. "wp --path=/tmp/wordpress"}
CURRENT=${1:?current ZIP}
PREVIOUS=${2:?previous ZIP}
fail() { echo "FAIL: $*" >&2; exit 1; }
version() { $WP eval 'echo QLW_VERSION;'; }

$WP plugin install "$CURRENT" --force --activate >/dev/null
NEW=$(version)
legacy_before=$($WP plugin is-active multi-ai-seo-content-factory >/dev/null 2>&1 && echo active || echo inactive)
id=$($WP post create --post_type=post --post_status=draft --post_title="QLW rollback $(date +%s)" --post_content="<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->" --porcelain)
$WP post meta update "$id" _qlw_markdown "## Phần một" >/dev/null
$WP post meta update "$id" _qlw_qa_score 88 >/dev/null
$WP option patch insert qlw_settings verified_facts "Honda Wave: 150.000đ/ngày" >/dev/null 2>&1 || $WP option patch update qlw_settings verified_facts "Honda Wave: 150.000đ/ngày" >/dev/null

$WP plugin install "$PREVIOUS" --force >/dev/null
OLD=$(version)
[ "$OLD" != "$NEW" ] || fail "previous ZIP has the same version $NEW"
$WP plugin is-active qwen-local-writer || fail "plugin inactive after rollback to $OLD"
[ "$($WP post get "$id" --field=post_status)" = draft ] || fail "draft changed after rollback"
[ "$($WP post meta get "$id" _qlw_markdown)" = "## Phần một" ] || fail "Markdown meta lost after rollback"
$WP eval 'wp_set_current_user(1); ob_start(); QLW\Plugin::render(); exit(str_contains(ob_get_clean(), "id=\"qlw-app\"") ? 0 : 1);' || fail "writer screen broken after rollback"
echo "ok - rollback $NEW -> $OLD keeps draft #$id, its meta and an active plugin"

$WP plugin install "$CURRENT" --force >/dev/null
[ "$(version)" = "$NEW" ] || fail "upgrade back to $NEW did not replace the files"
# The upgrade routine runs on admin_init (wp-cli is not wp-admin, so call the hooked method).
$WP eval 'if (!has_action("admin_init", [QLW\Plugin::class, "maybe_upgrade"])) { exit(1); } QLW\Plugin::maybe_upgrade();' || fail "upgrade routine not hooked to admin_init"
[ "$($WP option get qlw_version)" = "$NEW" ] || fail "stored version not updated on upgrade"
[ "$($WP post meta get "$id" _qlw_qa_score)" = 88 ] || fail "QA meta lost after upgrade"
[ "$($WP option pluck qlw_settings verified_facts)" = "Honda Wave: 150.000đ/ngày" ] || fail "settings lost across rollback/upgrade"
legacy_after=$($WP plugin is-active multi-ai-seo-content-factory >/dev/null 2>&1 && echo active || echo inactive)
[ "$legacy_before" = "$legacy_after" ] || fail "legacy plugin state changed ($legacy_before -> $legacy_after)"
$WP post delete "$id" --force >/dev/null
echo "ok - upgrade $OLD -> $NEW keeps data and updates qlw_version; legacy plugin $legacy_after"
echo "Rollback/upgrade test passed"
