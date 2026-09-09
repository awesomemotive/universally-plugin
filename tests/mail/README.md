# WooCommerce email regression checks

The suite boots a real WordPress/WooCommerce installation and exercises the actual
mail filter, HTTP client, and persisted order metadata. The `pre_http_request`
filter supplies controlled translator responses; no real customer email, Gemini
request, or production service is used. This is an integration test of the plugin
boundary, not a live translation or SMTP end-to-end test.

Run from the repository root after `bash scripts/build.sh` and installing the
existing `tests/playground` npm dependencies:

```sh
mkdir -p /tmp/universally-email-results
node tests/playground/node_modules/@wp-playground/cli/cli.js run-blueprint \
  --wp=7.1 --php=7.4 \
  --mount="$PWD/build/staging/universally-language-translation-multilingual-tool:/wordpress/wp-content/plugins/universally-language-translation-multilingual-tool" \
  --mount="$PWD/tests:/wordpress/qa" \
  --mount=/tmp/universally-email-results:/wordpress/qa-results \
  --blueprint="$PWD/tests/mail/blueprint.json"
cat /tmp/universally-email-results/mail-result.json
```

Use absolute host paths and mount fixtures under `/wordpress`, so Playground's
secondary PHP processes can access them. The blueprint installs WooCommerce from
WordPress.org, activates the staged plugin, then fails on any assertion.

Validated on WordPress 7.1, PHP 7.4, and WooCommerce 11.1.0:

- Password-reset and new-account emails are rejected even with an order object.
- Plain-text links stay out of outbound translator payloads and are restored per
  occurrence; missing/repeated/wrapped/literal markers preserve the original email.
- Both endpoints explicitly opt into backend numeric tokenization (#290).
- Transport errors, quota responses, and malformed data preserve the original.
- HTML requests use a full same-site reserved email path.
- Classic and Store API checkout capture persist the language on real orders.
- Disabling the setting makes no translation request.

The backend's reserved-page association and numeric-template behavior are tested
in awesomemotive/universally#290. Merge/deploy that counterpart before enabling
this opt-in feature. Generic `wp_mail` support must introduce a positive allowlist
of safe email types; account/reset messages remain excluded.
