<?php
/**
 * Universally helper functions for template usage
 *
 * @package Universally
 */

use Universally\Http;
use Universally\Log;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get the unified 64-char API key (public + private).
 */
function universally_get_api_key(): string
{
    if (defined('UNIVERSALLY_API_KEY')) {
        return trim(UNIVERSALLY_API_KEY);
    }
    return get_option('universally_api_key', '');
}

/**
 * Get the Universally app base URL (no trailing slash).
 *
 * Defaults to the production app, overridable in wp-config.php for
 * local/staging via `define('UNIVERSALLY_APP_URL', 'http://localhost:3000');`.
 * Single source of truth — every "Dashboard"/app link should resolve through
 * this so the override only lives in one place.
 */
function universally_get_app_url(): string
{
    $url = defined('UNIVERSALLY_APP_URL') ? UNIVERSALLY_APP_URL : 'https://app.universally.com';
    return rtrim($url, '/');
}

/**
 * Get the public API key (first 32 chars).
 */
function universally_get_public_api_key(): string
{
    $key = universally_get_api_key();
    if (strlen($key) !== 64) {
        return '';
    }
    return substr($key, 0, 32);
}

/**
 * Get the private API key (last 32 chars).
 */
function universally_get_private_api_key(): string
{
    $key = universally_get_api_key();
    if (strlen($key) !== 64) {
        return '';
    }
    return substr($key, 32, 32);
}

/**
 * Check if current page is a translated page
 *
 * @return bool True if page is translated, false otherwise
 */
function universally_is_translated_page()
{
    // Use the constant set by Buffer during URL detection
    return defined('UNIVERSALLY_CURRENT_LANG');
}

/**
 * Get current URL language code (e.g., "mx", "fr", "pt")
 *
 * Returns the short URL code from the current page URL.
 * Returns null if not on a translated page.
 *
 * @return string|null URL language code or null
 */
function universally_get_current_lang(): ?string
{
    return defined('UNIVERSALLY_CURRENT_LANG') ? UNIVERSALLY_CURRENT_LANG : null;
}

/**
 * Get current locale variant (e.g., "es-419", "fr-FR", "pt-BR")
 *
 * Returns the full locale variant used for API translation.
 * Returns null if not on a translated page.
 *
 * @return string|null Locale variant or null
 */
function universally_get_current_locale(): ?string
{
    return defined('UNIVERSALLY_CURRENT_LOCALE') ? UNIVERSALLY_CURRENT_LOCALE : null;
}

/**
 * Fetch all site languages (source + targets) from the API.
 *
 * Each language contains: name, originalName, region, flagUrl, lang,
 * variant, urlPrefix, isSource (bool), isDisabled (bool, targets only).
 *
 * @return array
 */
function universally_fetch_all_languages(): array
{
    $apiKey = universally_get_api_key();

    if (empty($apiKey)) {
        Log::error('API Key is not configured in settings.');
        return [];
    }

    $response = (new Http())->get('/connect/languages/all', [
        'X-API-Key' => $apiKey,
    ]);

    if (!$response || !isset($response['success'])) {
        Log::error('Failed to fetch languages from API: Invalid response structure');
        return [];
    }

    if (!$response['success']) {
        Log::error('API Error: ' . ($response['message'] ?? 'Failed to fetch languages'));
        return [];
    }

    return $response['data'] ?? [];
}

function universally_get_all_languages($forceRefresh = false): array
{
    $cacheKey = 'universally_all_languages';
    $languages = get_transient($cacheKey);

    if ($languages === false || $forceRefresh) {
        $languages = universally_fetch_all_languages();
        set_transient($cacheKey, $languages, 15 * MINUTE_IN_SECONDS);
    }

    /**
     * Filter the site's language set (source + targets).
     *
     * Applied after the cache read, so filtered values are never persisted.
     * Handle with care: UnifiedBuffer resolves a URL prefix to a translation
     * locale through this list, so dropping an entry stops that language being
     * translated, not merely displayed. To change only what visitors see,
     * filter universally_switcher_urls instead.
     *
     * @param array $languages    Each with name, originalName, region, flagUrl,
     *                            lang, variant, urlPrefix, isSource, isDisabled.
     * @param bool  $forceRefresh Whether the cache was bypassed.
     */
    $languages = apply_filters('universally_languages', $languages, $forceRefresh);

    return is_array($languages) ? $languages : [];
}

/**
 * Fetch site config (exclude pages, selectors) from the API.
 * Uses a short timeout to avoid blocking page loads if the API is down.
 *
 * @return array
 */
function universally_fetch_site_config(): array
{
    $apiKey = universally_get_api_key();

    if (empty($apiKey)) {
        return [];
    }

    $http = new \Universally\Http(null, 5);
    $response = $http->get('/connect/site-config', [
        'X-API-Key' => $apiKey,
    ]);

    if (!$response || empty($response['success'])) {
        return [];
    }

    return $response['data'] ?? [];
}

/**
 * Get cached exclude pages list.
 *
 * Returns an array of path patterns (e.g. "/checkout/", "/admin/*").
 * Cached for 15 minutes. Fails open: returns empty array on API failure
 * so the switcher is shown rather than hidden.
 *
 * @param bool $forceRefresh Force cache refresh
 * @return array
 */
function universally_get_site_config(bool $forceRefresh = false): array
{
    $cacheKey = 'universally_site_config';
    $config = get_transient($cacheKey);

    if ($config === false || $forceRefresh) {
        $config = universally_fetch_site_config();
        set_transient($cacheKey, $config, 15 * MINUTE_IN_SECONDS);
    }

    /**
     * Filter the site config fetched from the API (excludePages, siteId, …).
     *
     * Applied after the cache read, so filtered values are never persisted.
     *
     * @param array $config
     */
    $config = apply_filters('universally_site_config', is_array($config) ? $config : []);

    return is_array($config) ? $config : [];
}

function universally_get_exclude_pages(bool $forceRefresh = false): array
{
    $pages = universally_get_site_config($forceRefresh)['excludePages'] ?? [];

    /**
     * Filter the exclude-pages patterns.
     *
     * Each pattern is an exact path ("/checkout/") or a trailing wildcard
     * ("/admin/*"). Lets you keep pages untranslated in code rather than
     * maintaining the list in the dashboard.
     *
     * @param array $pages
     */
    $pages = apply_filters('universally_exclude_pages', is_array($pages) ? $pages : []);

    return is_array($pages) ? $pages : [];
}

/**
 * Get the connected project (site) id, used to deep-link into the dashboard
 * (e.g. {app}/projects/{id}/languages). Empty string if not connected or the
 * API doesn't report one yet. Sourced from the cached site config.
 */
function universally_get_site_id(): string
{
    $config = universally_get_site_config();
    // Self-heal: a site-config cached before the API exposed `siteId` won't have
    // it. Force one refresh (only while connected) so the dashboard deep-links
    // work immediately instead of after the 15-minute cache expires.
    if (!isset($config['siteId']) && universally_get_api_key() !== '') {
        $config = universally_get_site_config(true);
    }
    $id = $config['siteId'] ?? '';
    return is_string($id) ? $id : '';
}

/**
 * Check whether a path (already stripped of any language prefix) matches the
 * site's exclude-pages configuration.
 *
 * Supports exact paths (e.g. "/checkout/") and trailing wildcards
 * ("/admin/*"). Trailing slashes are ignored when comparing.
 */
function universally_path_is_excluded(string $path): bool
{
    $excludePages = universally_get_exclude_pages();
    $excluded = false;

    $normalized = '/' . ltrim($path, '/');
    $normalized = $normalized === '/' ? '/' : rtrim($normalized, '/');

    foreach ($excludePages as $pattern) {
        $p = trim((string) $pattern);
        if ($p === '') {
            continue;
        }

        if (substr($p, -2) === '/*') {
            $prefix = rtrim(substr($p, 0, -2), '/');
            if ($normalized === $prefix || strpos($normalized, $prefix . '/') === 0) {
                $excluded = true;
                break;
            }
        } else {
            if ($normalized === rtrim($p, '/')) {
                $excluded = true;
                break;
            }
        }
    }

    /**
     * Filter whether a path is excluded from translation.
     *
     * The final verdict, so it runs even when the pattern list is empty —
     * unlike universally_exclude_pages, which only reshapes the patterns.
     *
     * @param bool   $excluded   Whether the patterns matched.
     * @param string $normalized The path, normalized and prefix-stripped.
     * @param array  $patterns   The exclude patterns that were checked.
     */
    return (bool) apply_filters('universally_path_is_excluded', $excluded, $normalized, $excludePages);
}

/**
 * Get all languages with URLs and current status
 *
 * Returns all languages (source + targets) from the API with added URL and isCurrent fields.
 * Each language contains: name, originalName, flagUrl, urlPrefix, variant, region, isSource, url, isCurrent
 *
 * @return array Array of languages with complete data
 */
function universally_get_switcher_urls(): array
{
    $languages = universally_get_all_languages();

    if (empty($languages)) {
        return [];
    }

    $currentLang = universally_get_current_lang();
    // sanitize_text_field strips every %XX byte, which destroys emoji and non-Latin
    // slugs (e.g. /post-with-🎉/, /অ/). Keep the URL raw — esc_url is applied later
    // when each language URL is rendered into HTML attributes.
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- intentional; see comment above.
    $currentUrl = wp_unslash($_SERVER['REQUEST_URI'] ?? '/');
    $homeUrl = home_url();

    // Collect valid language prefixes for stripping
    $validPrefixes = [];
    foreach ($languages as $lang) {
        if (!empty($lang['urlPrefix'])) {
            $validPrefixes[] = $lang['urlPrefix'];
        }
    }

    // Strip any existing language prefix from the current URL
    $basePath = $currentUrl;
    foreach ($validPrefixes as $prefix) {
        if (preg_match('#^/' . preg_quote($prefix, '#') . '(/|$)#', $basePath)) {
            $basePath = preg_replace('#^/' . preg_quote($prefix, '#') . '(/?)#', '/$1', $basePath);
            break;
        }
    }

    // Resolved up front: collision handling needs to see the whole set at once.
    $hreflangCodes = universally_resolve_hreflang_codes($languages);

    // Add URL, isCurrent and hreflang to each language
    foreach ($languages as $key => &$lang) {
        if (!is_array($lang)) {
            continue;
        }

        /**
         * Filter the hreflang code emitted for a single language.
         *
         * Applies to both the wp_head hreflang tags and the language switcher's
         * link attributes, so the whole plugin speaks one format.
         *
         * @param string $code The resolved code (e.g. "pt-BR" or "pt").
         * @param array  $lang The language entry it was resolved from.
         */
        $lang['hreflang'] = apply_filters('universally_hreflang_code', $hreflangCodes[$key] ?? '', $lang);

        $urlPrefix = $lang['urlPrefix'] ?? '';

        if (!empty($lang['isSource'])) {
            $lang['url'] = $homeUrl . $basePath;
            $lang['isCurrent'] = ($currentLang === null);
        } elseif (!empty($urlPrefix)) {
            $lang['url'] = $homeUrl . '/' . $urlPrefix . $basePath;
            $lang['isCurrent'] = ($currentLang === $urlPrefix);
        }

        if (isset($lang['url'])) {
            /**
             * Filter the URL for a single language.
             *
             * For URL layouts the plugin doesn't build itself — a per-language
             * subdomain, or a translated slug.
             *
             * @param string $url      The path-prefixed URL.
             * @param array  $lang     The language entry.
             * @param string $basePath Current path, language prefix stripped.
             */
            $lang['url'] = (string) apply_filters('universally_language_url', $lang['url'], $lang, $basePath);
        }
    }
    unset($lang);

    /**
     * Filter the language set with URLs resolved for the current request.
     *
     * The canonical list behind both the language switcher and the hreflang
     * tags — the place to hide a language from visitors, reorder the switcher,
     * or hand the set to another plugin. Unlike universally_languages, changes
     * here don't affect which languages get translated.
     *
     * @param array $languages Each with url, isCurrent and hreflang added.
     */
    $languages = apply_filters('universally_switcher_urls', $languages);

    return is_array($languages) ? $languages : [];
}

/**
 * Get available target languages (excludes source)
 *
 * @return array Array of target languages only
 */
function universally_get_available_languages(): array
{
    $languages = universally_get_switcher_urls();

    // Filter to return only target languages (not source)
    return array_filter($languages, function ($lang) {
        return !isset($lang['isSource']) || $lang['isSource'] !== true;
    });
}

/**
 * Get language switcher HTML
 *
 * @param array $args Shortcode attributes (show_flags, show_names, flag_style)
 * @return string HTML output
 */
function universally_get_switcher(array $args = []): string
{
    $switcher = new \Universally\LanguageSwitcher();
    return $switcher->renderShortcode($args);
}

/**
 * Echo language switcher HTML
 *
 * @param array $args Shortcode attributes (show_flags, show_names, flag_style)
 */
function universally_switcher(array $args = [])
{
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- universally_get_switcher returns pre-escaped HTML
    echo universally_get_switcher($args);
}

/**
 * Which hreflang code format to emit.
 *
 * `region` (the default, and what the plugin has always emitted) keeps the
 * region-qualified code the API reports: fr-FR, pt-BR. `language` drops the
 * region so a single translation serves every speaker of that language: fr, pt.
 * Controlled by the "Hreflang Format" setting in the Preferences tab.
 *
 * @return string 'region' or 'language'
 */
function universally_get_hreflang_format(): string
{
    $settings = get_option('universally_settings', []);
    $format = is_array($settings) ? ($settings['hreflang_format'] ?? 'region') : 'region';

    /**
     * Filter the hreflang code format, overriding the setting.
     *
     * @param string $format Either 'region' or 'language'. Anything else
     *                       falls back to 'region'.
     */
    $format = apply_filters('universally_hreflang_format', $format);

    return $format === 'language' ? 'language' : 'region';
}

/**
 * Reduce a locale code to its language (and script) subtags, dropping the region.
 *
 * fr-FR → fr, pt-BR → pt, es-419 → es. Script subtags are kept and Titlecased
 * (zh-hans → zh-Hans) because Simplified and Traditional Chinese are distinct
 * written languages with distinct hreflang values — collapsing both to `zh`
 * would serve the wrong script to half the audience. Per BCP 47 a 4-alpha
 * subtag is a script, while 2-alpha and 3-digit subtags are regions.
 *
 * @param string $code Locale code (e.g. "pt-BR").
 * @return string Language-only code (e.g. "pt").
 */
function universally_strip_hreflang_region(string $code): string
{
    $parts = explode('-', $code);
    $result = [strtolower(array_shift($parts))];

    foreach ($parts as $part) {
        if (strlen($part) === 4 && ctype_alpha($part)) {
            $result[] = ucfirst(strtolower($part));
        }
    }

    return implode('-', $result);
}

/**
 * Resolve the hreflang code for each language in the set.
 *
 * Keyed by the same keys as $languages. In `language` format two locales can
 * reduce to the same code (fr-FR and fr-CA both → fr), which would emit
 * duplicate hreflang values — invalid markup that search engines discard.
 * When that happens the whole colliding group keeps its full region code, so
 * no language drops out of the alternates set.
 *
 * @param array $languages Languages from universally_get_all_languages().
 * @return array Map of language key → hreflang code.
 */
function universally_resolve_hreflang_codes(array $languages): array
{
    $base = [];

    foreach ($languages as $key => $lang) {
        if (!is_array($lang)) {
            continue;
        }

        // Region first, variant as fallback — the plugin's original behavior.
        $code = !empty($lang['region']) ? $lang['region'] : ($lang['variant'] ?? '');

        if ($code !== '') {
            $base[$key] = $code;
        }
    }

    if (universally_get_hreflang_format() !== 'language') {
        return $base;
    }

    $reduced = array_map('universally_strip_hreflang_region', $base);
    $counts = array_count_values($reduced);

    foreach ($reduced as $key => $code) {
        if ($counts[$code] > 1) {
            $reduced[$key] = $base[$key];
        }
    }

    return $reduced;
}

/**
 * Build the hreflang alternates as a code => URL map.
 *
 * The map is keyed by hreflang code, so duplicates collapse rather than
 * producing the conflicting tag pairs search engines discard. x-default is
 * appended last, pointing at the source language.
 *
 * @return array Map of hreflang code => absolute URL.
 */
function universally_get_hreflang_links(): array
{
    $languages = universally_get_switcher_urls();
    $links = [];
    $sourceUrl = '';

    foreach ($languages as $lang) {
        if (!is_array($lang) || empty($lang['url'])) {
            continue;
        }

        // Resolved in universally_get_switcher_urls(), honoring the
        // "Hreflang Format" setting and the universally_hreflang_code filter.
        $code = $lang['hreflang'] ?? '';

        if ($code === '') {
            continue;
        }

        $links[$code] = $lang['url'];

        // x-default target — captured here so it inherits the same guards.
        if (!empty($lang['isSource'])) {
            $sourceUrl = $lang['url'];
        }
    }

    if ($sourceUrl !== '') {
        $links['x-default'] = $sourceUrl;
    }

    /**
     * Filter the hreflang alternates before they are rendered.
     *
     * Keys are hreflang codes, values absolute URLs. Add, remove or retarget
     * entries without touching markup — escaping is applied afterwards, so
     * return plain strings. Return an empty array to emit nothing.
     *
     * @param array $links     Map of hreflang code => URL.
     * @param array $languages Languages with url, isCurrent and hreflang set.
     */
    $links = apply_filters('universally_hreflang_links', $links, $languages);

    if (!is_array($links)) {
        return [];
    }

    // Filters are third-party code: drop anything unrenderable rather than
    // emitting a malformed tag.
    $clean = [];
    foreach ($links as $code => $url) {
        $code = trim((string) $code);
        $url = is_scalar($url) ? trim((string) $url) : '';

        if ($code !== '' && $url !== '') {
            $clean[$code] = $url;
        }
    }

    return $clean;
}

/**
 * Get hreflang tags HTML
 *
 * @return string HTML markup for hreflang tags
 */
function universally_get_hreflang_tags(): string
{
    $links = universally_get_hreflang_links();

    if (empty($links)) {
        return '';
    }

    $output = "\n<!-- Universally hreflang tags -->\n";

    foreach ($links as $code => $url) {
        $output .= sprintf(
            '<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
            esc_attr($code),
            esc_url($url)
        );
    }

    /**
     * Filter the complete hreflang tag markup before it is printed.
     *
     * Prefer the universally_hreflang_links filter for adding or removing
     * alternates. Use this one to replace the markup wholesale, or return an
     * empty string to suppress it — no need to remove the wp_head action.
     *
     * @param string $output The full markup, already escaped.
     * @param array  $links  Map of hreflang code => URL it was rendered from.
     */
    return apply_filters('universally_hreflang_tags', $output, $links);
}

/**
 * Output hreflang tags
 */
function universally_hreflang_tags()
{
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- universally_get_hreflang_tags returns pre-escaped HTML
    echo universally_get_hreflang_tags();
}

/**
 * Whether to block browser auto-translation on the front end.
 *
 * Defaults to false when the setting hasn't been saved. Controlled by the
 * "Prevent browser auto-translation" toggle in the Preferences tab.
 *
 * @return bool
 */
function universally_prevent_browser_translation_enabled(): bool
{
    $settings = get_option('universally_settings', []);

    if (!is_array($settings) || !array_key_exists('prevent_browser_translation', $settings)) {
        return false;
    }

    return (bool) $settings['prevent_browser_translation'];
}

/**
 * Whether to also block browser auto-translation on the original (source) language
 * pages, not just on translated pages.
 *
 * Defaults to false when the setting hasn't been saved, so by default the
 * notranslate tags are emitted only on translated pages. Controlled by the
 * "Also prevent it on the original language" toggle in the Preferences tab, which
 * only applies when "Prevent browser auto-translation" is enabled.
 *
 * @return bool
 */
function universally_prevent_browser_translation_on_source_enabled(): bool
{
    $settings = get_option('universally_settings', []);

    if (!is_array($settings) || !array_key_exists('prevent_browser_translation_source', $settings)) {
        return false;
    }

    return (bool) $settings['prevent_browser_translation_source'];
}

/**
 * Whether the notranslate tags should be emitted for the current request.
 *
 * Requires the master toggle to be on. Translated pages always qualify; the
 * original (source) language page qualifies only when the source-language toggle
 * is also enabled.
 *
 * @return bool
 */
function universally_should_emit_notranslate(): bool
{
    if (!universally_prevent_browser_translation_enabled()) {
        $should = false;
    } elseif (universally_is_translated_page()) {
        $should = true;
    } else {
        $should = universally_prevent_browser_translation_on_source_enabled();
    }

    /**
     * Filter whether the notranslate tags are emitted for this request.
     *
     * The single verdict behind both the meta tag and the translate="no"
     * attribute, so one filter covers both — useful to exempt a landing page,
     * or to opt in per template without touching the settings.
     *
     * @param bool $should
     */
    return (bool) apply_filters('universally_should_emit_notranslate', $should);
}

/**
 * Emit a notranslate meta tag (Google / Chrome) so the browser doesn't offer to
 * auto-translate the page — visitors should use the site's Universally
 * translations instead. Hooked on wp_head.
 */
function universally_notranslate_meta(): void
{
    if (!universally_should_emit_notranslate()) {
        return;
    }

    echo '<meta name="google" content="notranslate" />' . "\n";
}

/**
 * Add the cross-browser translate="no" attribute to the <html> tag — honored by
 * Chrome, Edge, Firefox, and Safari. Hooked on the language_attributes filter so
 * it composes with WordPress's own lang="…" output.
 *
 * @param string $output Existing <html> attributes (e.g. lang="en-US").
 * @return string
 */
function universally_html_translate_attr(string $output): string
{
    if (universally_should_emit_notranslate() && strpos($output, 'translate=') === false) {
        $output .= ' translate="no"';
    }

    return $output;
}
