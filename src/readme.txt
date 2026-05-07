=== Universally Language Translation Multilingual Tool ===
Contributors: benjaminprojas, _smartik_, smub
Tags: translate, translation, multilingual, language, localization
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Translate your website into 110+ languages. Automatic AI translations with SEO-friendly URLs and hreflang tags. Free to start.

== Description ==

**Universally** is the easiest way to make your website multilingual. Install the plugin, connect your site, choose your languages — and every page is automatically translated with clean, SEO-friendly URLs. No coding, no manual string editing, no complex configuration.

Your visitors see translated pages at URLs like `yoursite.com/fr/` or `yoursite.com/es/`, with proper hreflang tags and translated metadata so search engines index every language version correctly.

= How It Works =

1. **Install & connect** — Install the plugin and enter your free API key.
2. **Choose your languages** — Select from 110+ languages in the dashboard.
3. **You're done** — Your site is now multilingual. Translations happen automatically.

= Features =

**Automatic Translation**

* AI-powered translations into 110+ languages
* Automatic content detection — pages, posts, menus, widgets, and dynamic content
* Works at the HTML level so it translates everything visitors see, regardless of how the content is built

**Translation Glossary**

* Define rules for how specific terms are translated
* Keep terms untranslated — perfect for brand names, product names, and technical terms
* Override translations — specify exactly how a term should be translated per language
* Case-sensitive matching for precise control

**Multilingual SEO**

* Clean URL structure — `/fr/`, `/es/`, `/de/` prefixes for each language
* Automatic hreflang tags on every page
* Translated page metadata for proper search engine indexing
* Each language version is a fully crawlable, indexable page

**Language Switcher**

* Built-in language switcher with country flags
* Auto-placement (bottom-right, bottom-left, top-right, top-left) or manual placement
* Available as a shortcode, Gutenberg block, or PHP function
* Fully customizable — colors, borders, border radius, flag style

**Works With Everything**

* Compatible with any theme
* WooCommerce — products, categories, cart, and checkout
* Page builders — SeedProd, Elementor, Divi, Beaver Builder, Gutenberg, and more
* SEO plugins — All in One SEO, Yoast SEO, Rank Math
* Caching plugins and CDNs

= Why Universally? =

Universally translates your entire page in the cloud and returns the fully translated HTML. This means zero database bloat on your WordPress installation, instant compatibility with any plugin or theme, and translations that stay fast because they're cached on our edge network.

= Free Plan =

Get started for free — no credit card required. The free plan includes everything you need to start translating your site. [See pricing](https://universally.com/pricing/) for details on all plans.

= External Service =

This plugin connects to the [Universally](https://universally.com/) translation service to process translations. When a visitor requests a translated page, the page content is sent to Universally servers for translation and the translated HTML is returned to the visitor.

The page source code is not stored. Universally only stores the individual translated strings so they can be reused on future visits without re-translating. Plugin settings let you exclude specific strings, selectors, or pages from translation, so anything you don't want sent to the service can be opted out at the source.

* Service website: [universally.com](https://universally.com/)
* Terms of Service: [universally.com/terms/](https://universally.com/terms/)
* Privacy Policy: [universally.com/privacy/](https://universally.com/privacy/)

== Source Code ==

The full, human-readable source code for this plugin (TypeScript/React + SCSS) is available in a public GitHub repository:

* Public repository: [https://github.com/awesomemotive/universally-plugin](https://github.com/awesomemotive/universally-plugin)

The compiled JavaScript and CSS shipped in `panel/build/` are generated from the TypeScript/SCSS sources in `panel/js/` using [@wordpress/scripts](https://www.npmjs.com/package/@wordpress/scripts) (webpack under the hood). The original sources, build configuration (`webpack.config.js`, `tsconfig.json`, `package.json`), and dependency manifest (`package-lock.json`) are all available in the repository above.

= Build Instructions =

To build the plugin's JavaScript and CSS assets from source:

1. Clone the repository: `git clone https://github.com/awesomemotive/universally-plugin.git`
2. Change into the plugin directory: `cd universally-plugin`
3. Install Node.js dependencies (Node.js 18+ required): `npm install`
4. Build production assets: `npm run build`
5. (Optional) Run the watcher during development: `npm run start`

The `npm run build` step regenerates everything inside `panel/build/` (compiled JS, CSS, source maps, and `*.asset.php` dependency files) from the sources in `panel/js/`.

= Bundled Third-Party Libraries =

The compiled JavaScript in `panel/build/index.js` includes the following third-party open-source library, which is declared in `package.json` and resolved via npm during the build:

* [@timkit/conditions](https://www.npmjs.com/package/@timkit/conditions) (Apache-2.0) — small condition evaluator used to drive dynamic visibility of settings fields.

All other JavaScript dependencies (React, `@wordpress/*` packages) are externalized at build time and resolved to the script handles that WordPress already provides — they are not bundled into the plugin's distributed files.

== Installation ==

1. Install the plugin from the WordPress plugin directory, or upload the `universally-language-translation-multilingual-tool` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to the Universally settings page and enter your API key. You can get a free API key at [universally.com/pricing/](https://universally.com/pricing/).
4. Select your target languages in the Universally dashboard and your site is ready.

== Frequently Asked Questions ==

= Do I need any coding skills to use Universally? =

No. Universally works out of the box. Install the plugin, enter your API key, and your site is automatically translated. No coding, no template editing, no shortcodes required (though shortcodes are available if you want manual control).

= How does the translation work? =

When a visitor requests a translated page (e.g. `yoursite.com/fr/about/`), Universally translates the full page content using AI and returns the translated HTML. Translations are cached so subsequent visits are served instantly.

= Which languages are supported? =

Universally supports 110+ languages including Spanish, French, German, Portuguese, Chinese, Japanese, Arabic, Hindi, and many more. You can see the full list in your Universally dashboard.

= Does it work with WooCommerce? =

Yes. Universally translates everything visitors see, including product pages, categories, cart, and checkout. Since it works at the HTML level, it's compatible with WooCommerce out of the box.

= Does it work with page builders? =

Yes. Universally is compatible with Elementor, Divi, Beaver Builder, WPBakery, Gutenberg, and any other page builder. Because translation happens at the output level, it doesn't matter how the content is built.

= Is it SEO friendly? =

Yes. Each language gets its own URL prefix (e.g. `/fr/`, `/es/`), hreflang tags are added automatically, and page metadata is translated. Search engines can crawl and index each language version as a separate page.

= Can I customize the language switcher? =

Yes. You can choose between auto-placement or manual placement via shortcode, Gutenberg block, or PHP function. The switcher is fully customizable — you can control colors, borders, border radius, flag style (rounded or square), and whether to show language names and/or flags.

= Where are translations stored? =

Translations are processed and cached on Universally's servers. This means no additional database tables or bloat on your WordPress installation.

= Is there a free plan? =

Yes. You can get started for free at [universally.com/pricing/](https://universally.com/pricing/). No credit card required.

= Where can I get support? =

You can reach our support team through the [Universally dashboard](https://app.universally.com/) or visit our [documentation](https://universally.com/docs/).

== Screenshots ==

1. General settings — Connect your site with an API key and view your configured languages.
2. Language switcher auto placement — Choose from 4 auto-placement options for the language switcher: bottom-right, bottom-left, top-right, and top-left.
3. Language switcher custom placement — Use the shortcode `[universally_switcher]` or the Gutenberg block to place the language switcher anywhere on your site.
4. Styling customization — Customize trigger and dropdown colors, borders, and border radius.
5. Admin bar menu — Quick access to settings, dashboard, and documentation.
6. Language switcher on the frontend — Dropdown with country flags and language names.
7. Project management dashboard — Manage your site languages, glossary, project settings, members, and view translation usage.

== Changelog ==

= 1.0.0 =
* Initial release.