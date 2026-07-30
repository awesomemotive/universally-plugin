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

    return $languages;
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

    return is_array($config) ? $config : [];
}

function universally_get_exclude_pages(bool $forceRefresh = false): array
{
    return universally_get_site_config($forceRefresh)['excludePages'] ?? [];
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

    if (empty($excludePages)) {
        return false;
    }

    $normalized = '/' . ltrim($path, '/');
    $normalized = $normalized === '/' ? '/' : rtrim($normalized, '/');

    foreach ($excludePages as $pattern) {
        $p = trim($pattern);
        if ($p === '') {
            continue;
        }

        if (substr($p, -2) === '/*') {
            $prefix = rtrim(substr($p, 0, -2), '/');
            if ($normalized === $prefix || strpos($normalized, $prefix . '/') === 0) {
                return true;
            }
        } else {
            if ($normalized === rtrim($p, '/')) {
                return true;
            }
        }
    }

    return false;
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

    // Add URL and isCurrent to each language
    foreach ($languages as &$lang) {
        if (!is_array($lang)) {
            continue;
        }

        $urlPrefix = $lang['urlPrefix'] ?? '';

        if (!empty($lang['isSource'])) {
            $lang['url'] = $homeUrl . $basePath;
            $lang['isCurrent'] = ($currentLang === null);
        } elseif (!empty($urlPrefix)) {
            $lang['url'] = $homeUrl . '/' . $urlPrefix . $basePath;
            $lang['isCurrent'] = ($currentLang === $urlPrefix);
        }
    }

    return $languages;
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
 * Get hreflang tags HTML
 *
 * @return string HTML markup for hreflang tags
 */
function universally_get_hreflang_tags(): string
{
    $languages = universally_get_switcher_urls();

    if (empty($languages)) {
        return '';
    }

    $output = "\n<!-- Universally hreflang tags -->\n";
    $sourceUrl = '';

    // Generate hreflang tags for all languages
    foreach ($languages as $lang) {
        if (!is_array($lang) || empty($lang['url'])) {
            continue;
        }

        // Use region for hreflang (or variant as fallback)
        $hreflang = !empty($lang['region']) ? $lang['region'] : $lang['variant'] ?? '';

        if (empty($hreflang)) {
            continue;
        }

        $output .= sprintf(
            '<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
            esc_attr($hreflang),
            esc_url($lang['url'])
        );

        // Store source URL for x-default
        if (isset($lang['isSource']) && $lang['isSource'] === true) {
            $sourceUrl = $lang['url'];
        }
    }

    // Add x-default pointing to source language
    if (!empty($sourceUrl)) {
        $output .= sprintf(
            '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
            esc_url($sourceUrl)
        );
    }

    return $output;
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
        return false;
    }

    if (universally_is_translated_page()) {
        return true;
    }

    return universally_prevent_browser_translation_on_source_enabled();
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

/**
 * Whether a path (already stripped of any language prefix) points at an
 * endpoint the translator can never process: WordPress system endpoints,
 * feeds, or file-like URLs (sitemap.xml, robots.txt, favicon.ico, …).
 *
 * Requests to such paths under a /{lang}/ prefix are 301-redirected to the
 * source URL instead of serving untranslated content at a translated URL.
 *
 * @param string $path URL path, e.g. "/feed/" or "/wp-sitemap.xml".
 * @return bool
 */
function universally_path_is_non_translatable(string $path): bool
{
    $isNonTranslatable = (bool) (
        preg_match('#^/(wp-admin|wp-includes|wp-content|wp-login\.php|wp-json|wp-cron\.php|wp-trackback\.php|wp-comments-post\.php|xmlrpc\.php)(/|$)#', $path)
        || preg_match('#(^|/)feed(/|$)#', $path)
        || preg_match('/\.[a-z0-9]{1,5}$/i', $path)
    );

    /**
     * Filter whether a path is excluded from translated routing.
     *
     * @param bool   $isNonTranslatable Default classification.
     * @param string $path              The URL path being classified.
     */
    return (bool) apply_filters('universally_path_is_non_translatable', $isNonTranslatable, $path);
}

/**
 * Resolve a URL language code (e.g. "es") to its locale variant (e.g. "es-MX").
 *
 * @param string $urlCode Lowercase URL prefix code.
 * @return string|false Locale variant, or false when the code is not an
 *                      enabled target language.
 */
function universally_resolve_url_code_to_locale(string $urlCode)
{
    $allLanguages = universally_get_all_languages();

    if (!is_array($allLanguages) || empty($allLanguages)) {
        return false;
    }

    foreach ($allLanguages as $language) {
        if (isset($language['urlPrefix']) && $language['urlPrefix'] === $urlCode && empty($language['isDisabled'])) {
            return $language['variant'] ?? false;
        }
    }

    return false;
}

/**
 * Prefix a same-origin URL with /{langCode}/. Returns the URL unchanged when
 * it points at another host, hits a WordPress system path, or already carries
 * a valid language prefix.
 */
function universally_prefix_url_with_language(string $url, string $langCode): string
{
    $parsed = wp_parse_url($url);
    $path = $parsed['path'] ?? '/';

    $siteHost = wp_parse_url(home_url(), PHP_URL_HOST);
    if (!empty($parsed['host']) && $parsed['host'] !== $siteHost) {
        return $url;
    }

    if (preg_match('/^\/(wp-admin|wp-includes|wp-content|wp-login\.php|wp-json)/', $path)) {
        return $url;
    }

    $firstSegment = strtolower(explode('/', trim($path, '/'))[0] ?? '');
    if ($firstSegment !== '' && universally_resolve_url_code_to_locale($firstSegment) !== false) {
        return $url;
    }

    $newPath = '/' . $langCode . $path;
    $query = !empty($parsed['query']) ? '?' . $parsed['query'] : '';

    if (!empty($parsed['host'])) {
        $scheme = $parsed['scheme'] ?? 'https';
        return $scheme . '://' . $parsed['host'] . $newPath . $query;
    }

    return $newPath . $query;
}
