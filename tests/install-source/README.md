# Install source regression checks

Build the production plugin, then run its activation lifecycle and public
connect URL builder in WordPress Playground (WordPress 7.1, PHP 7.4):

```sh
npm run build
npm ci --prefix tests/playground
mkdir -p build/install-source-results
tests/playground/node_modules/.bin/cli run-blueprint \
  --blueprint tests/install-source/blueprint.json \
  --mount "$PWD/build/staging/universally-language-translation-multilingual-tool:/wordpress/wp-content/plugins/universally-language-translation-multilingual-tool" \
  --mount "$PWD/tests/install-source:/wordpress/qa" \
  --mount "$PWD/build/install-source-results:/wordpress/qa-results"
```

`build/install-source-results/install-source.json` records the actual WordPress
and PHP versions and each assertion. Failures also throw from the blueprint.
The fixture covers eight partner page families, WordPress search and plugin
screens, unrelated query terms, malformed values, first activation persistence,
late partner overrides, the 64-character source contract, missing attribution,
and programmatic activation. It runs WordPress and its SQLite-backed options
API with the staged plugin; it does not contact the hosted app or require a
partner plugin installation.

The hosted app must accept 64-character sources before this feature is released
with long partner placements (awesomemotive/universally#418). The optional partner
option `universally_installed_by` is read when the connect URL is built, including
when it was written after activation. Referrer attribution remains best effort.
