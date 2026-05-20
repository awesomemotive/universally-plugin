<?php
/**
 * Unified buffer for language detection and translation
 *
 * Handles URL-based language detection, WordPress URL rewriting,
 * and output buffer translation via the Universally API.
 *
 * @package Universally
 */

namespace Universally;

if (!defined('ABSPATH')) {
    exit;
}

class UnifiedBuffer
{
    private const LANG_COOKIE = 'universally_lang';

    public function __construct()
    {
        add_action('init', [$this, 'setup'], 1);
    }

    private function targetLanguages(): array
    {
        $targetLanguages = universally_get_all_languages();

        if (!is_array($targetLanguages)) {
            return [];
        }

        return $targetLanguages;
    }

    /**
     * @return array|false
     */
    private function translate(string $html, string $targetLanguage, string $sourceUrl)
    {
        $apiKey = universally_get_api_key();

        if (empty($apiKey)) {
            Log::error('API Key is not configured in settings.');
            return false;
        }

        return (new Http(UNIVERSALLY_TRANSLATOR_URL))->post(
            '/v1/translate',
            [
                'html' => $html,
                'targetLanguage' => $targetLanguage,
                'sourceUrl' => $sourceUrl,
            ],
            ['X-API-Key' => $apiKey],
            true
        );
    }

    public function setup(): void
    {
        if (is_admin()) {
            return;
        }

        $isGet = isset($_SERVER['REQUEST_METHOD']) && 'GET' === $_SERVER['REQUEST_METHOD'];

        if (!$isGet) {
            // Non-GET requests (e.g. POST to wp-comments-post.php) don't carry the language
            // prefix in the URL, so derive it from the referrer and only re-prefix redirects.
            $refererLang = $this->detectLanguageFromReferer();
            if ($refererLang !== false) {
                $this->preserveLanguagePrefixOnRedirects($refererLang);
            }
            return;
        }

        $detected = $this->detectLanguage();

        if ($detected === false) {
            // No language prefix in the URL — honor the visitor's stored preference
            // unless they've explicitly opted back into the source language via the switcher.
            $preferredLang = $this->getPreferredLanguageFromCookie();
            if ($preferredLang !== null) {
                $this->redirectToPreferredLanguage($preferredLang);
            }
            return;
        }

        [$langCode, $targetLocale, $pathAfterPrefix] = $detected;

        // Redirect /lang to /lang/ (add trailing slash)
        if ($pathAfterPrefix === null) {
            $this->redirectToTrailingSlash($langCode);
        }

        // Remember the visitor's language so later unprefixed navigations stay translated.
        $this->setLanguageCookie($langCode);

        // Excluded pages aren't translated — bounce off the /{lang}/ URL so the user
        // lands on the canonical source URL. The cookie still steers future navigations
        // back into the translated experience.
        if (universally_path_is_excluded($pathAfterPrefix)) {
            $this->redirectToSource($pathAfterPrefix);
        }

        define('UNIVERSALLY_CURRENT_LANG', $langCode);
        define('UNIVERSALLY_CURRENT_LOCALE', $targetLocale);

        $this->stripLanguagePrefix($pathAfterPrefix);
        $this->preserveLanguagePrefixOnRedirects($langCode);

        ob_start([$this, 'translateBuffer']);
    }

    /**
     * Detect language prefix from the current request URI.
     *
     * @return array{string, string, string|null}|false [langCode, targetLocale, pathAfterPrefix] or false
     */
    private function detectLanguage()
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- URL is parsed by wp_parse_url; sanitize_text_field would strip percent-encoded UTF-8 (emoji, non-Latin slugs).
        $requestUri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        return $this->parseLanguageFromUrl($requestUri);
    }

    /**
     * Detect the language prefix of the request's referrer (same-origin only).
     * Used on non-GET requests like comment POSTs, which target prefix-less endpoints.
     *
     * @return string|false langCode or false
     */
    private function detectLanguageFromReferer()
    {
        if (empty($_SERVER['HTTP_REFERER'])) {
            return false;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed by wp_parse_url; same UTF-8 concern as REQUEST_URI.
        $referer = wp_unslash($_SERVER['HTTP_REFERER']);
        $refererHost = wp_parse_url($referer, PHP_URL_HOST);
        $siteHost = wp_parse_url(home_url(), PHP_URL_HOST);

        if ($refererHost !== $siteHost) {
            return false;
        }

        $parsed = $this->parseLanguageFromUrl($referer);
        if ($parsed === false) {
            return false;
        }

        return $parsed[0];
    }

    /**
     * @return array{string, string, string|null}|false [langCode, targetLocale, pathAfterPrefix] or false
     */
    private function parseLanguageFromUrl(string $url)
    {
        $parsedUri = wp_parse_url($url);
        $rawPath = $parsedUri['path'] ?? '/';

        // Match the language prefix on a decoded copy of the path so that a percent-encoded
        // first segment (e.g. an emoji or non-Latin slug like /bn/অ/) doesn't accidentally
        // match the [a-z0-9-]{2,6} prefix pattern. Keep the original $rawPath for $pathAfterPrefix
        // so emoji/non-Latin slugs survive intact when written back to $_SERVER['REQUEST_URI'].
        $decodedPath = rawurldecode($rawPath);

        if (!preg_match('/^\/([a-z0-9-]{2,6})(\/.*)?$/i', $decodedPath, $matches)) {
            return false;
        }

        $langCode = strtolower($matches[1]);
        $targetLocale = $this->resolveUrlCodeToLocale($langCode);

        if ($targetLocale === false) {
            return false;
        }

        // Strip the (ASCII) /{langCode} prefix off the raw path so percent-encoded
        // characters in the remaining slug are preserved verbatim for WP's router.
        $rawAfterPrefix = substr($rawPath, strlen('/' . $matches[1]));
        $pathAfterPrefix = $rawAfterPrefix !== '' ? $rawAfterPrefix : null;

        return [$langCode, $targetLocale, $pathAfterPrefix];
    }

    private function redirectToSource(string $pathAfterPrefix): void
    {
        $redirectUrl = $pathAfterPrefix === '' ? '/' : $pathAfterPrefix;
        if (!empty($_SERVER['QUERY_STRING'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw wraps the full URL below.
            $redirectUrl .= '?' . wp_unslash($_SERVER['QUERY_STRING']);
        }
        wp_safe_redirect(esc_url_raw(home_url($redirectUrl)), 301);
        exit;
    }

    /**
     * For visitors with a stored language preference, redirect unprefixed GET
     * requests to the matching /{lang}/ URL. Skips WordPress system endpoints,
     * file-like paths, and pages excluded from translation.
     */
    private function redirectToPreferredLanguage(string $langCode): void
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed by wp_parse_url; sanitize_text_field would corrupt percent-encoded UTF-8.
        $requestUri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $parsed = wp_parse_url($requestUri);
        $path = $parsed['path'] ?? '/';

        // Skip WordPress system endpoints
        if (preg_match('#^/(wp-admin|wp-includes|wp-content|wp-login\.php|wp-json|wp-cron\.php|wp-trackback\.php|wp-comments-post\.php|xmlrpc\.php)(/|$)#', $path)) {
            return;
        }

        // Skip file-like paths (sitemap.xml, favicon.ico, robots.txt, etc.)
        if (preg_match('/\.[a-z0-9]{1,5}$/i', $path)) {
            return;
        }

        // Skip pages excluded from translation — they live at the source URL.
        if (universally_path_is_excluded($path)) {
            return;
        }

        $newPath = '/' . $langCode . $path;
        $query = !empty($parsed['query']) ? '?' . $parsed['query'] : '';

        wp_safe_redirect(esc_url_raw(home_url($newPath . $query)), 302);
        exit;
    }

    private function setLanguageCookie(string $langCode): void
    {
        if (headers_sent()) {
            return;
        }

        $existing = isset($_COOKIE[self::LANG_COOKIE]) ? (string) $_COOKIE[self::LANG_COOKIE] : '';
        if ($existing === $langCode) {
            return;
        }

        setcookie(
            self::LANG_COOKIE,
            $langCode,
            [
                'expires' => time() + 30 * DAY_IN_SECONDS,
                'path' => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
                'domain' => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
                'secure' => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]
        );
        // Reflect into $_COOKIE so later code in this request sees the new value.
        $_COOKIE[self::LANG_COOKIE] = $langCode;
    }

    private function getPreferredLanguageFromCookie(): ?string
    {
        if (empty($_COOKIE[self::LANG_COOKIE])) {
            return null;
        }

        $lang = strtolower(sanitize_key((string) wp_unslash($_COOKIE[self::LANG_COOKIE])));
        if ($lang === '') {
            return null;
        }

        if ($this->resolveUrlCodeToLocale($lang) === false) {
            return null;
        }

        return $lang;
    }

    private function redirectToTrailingSlash(string $langCode): void
    {
        $redirectUrl = '/' . $langCode . '/';
        if (!empty($_SERVER['QUERY_STRING'])) {
            // Don't run the query string through sanitize_text_field — it strips every
            // %XX byte, which destroys emoji and non-Latin characters.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw wraps the full URL below.
            $redirectUrl .= '?' . wp_unslash($_SERVER['QUERY_STRING']);
        }
        wp_safe_redirect(esc_url_raw(home_url($redirectUrl)), 301);
        exit;
    }

    private function stripLanguagePrefix(string $pathAfterPrefix): void
    {
        $newRequestUri = $pathAfterPrefix;
        if (!empty($_SERVER['QUERY_STRING'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- written back to $_SERVER for WP to consume; sanitize_text_field would corrupt percent-encoded UTF-8.
            $newRequestUri .= '?' . wp_unslash($_SERVER['QUERY_STRING']);
        }
        $_SERVER['REQUEST_URI'] = $newRequestUri;
    }

    /**
     * Preserve language prefix on any WordPress redirect (trailing slash, canonical, slug changes).
     */
    private function preserveLanguagePrefixOnRedirects(string $langCode): void
    {
        add_filter('wp_redirect', function (string $location) use ($langCode): string {
            $parsed = wp_parse_url($location);
            $path = $parsed['path'] ?? '/';

            // Ignore external redirects
            $siteHost = wp_parse_url(home_url(), PHP_URL_HOST);
            if (!empty($parsed['host']) && $parsed['host'] !== $siteHost) {
                return $location;
            }

            // Ignore WordPress system paths
            if (preg_match('/^\/(wp-admin|wp-includes|wp-content|wp-login\.php|wp-json)/', $path)) {
                return $location;
            }

            // Skip if redirect already has a valid language prefix
            $firstSegment = strtolower(explode('/', trim($path, '/'))[0] ?? '');
            if ($firstSegment !== '' && $this->resolveUrlCodeToLocale($firstSegment) !== false) {
                return $location;
            }

            // Re-add the language prefix
            $newPath = '/' . $langCode . $path;
            $query = !empty($parsed['query']) ? '?' . $parsed['query'] : '';

            if (!empty($parsed['host'])) {
                $scheme = $parsed['scheme'] ?? 'https';
                return $scheme . '://' . $parsed['host'] . $newPath . $query;
            }

            return $newPath . $query;
        });
    }

    public function translateBuffer(string $buffer): string
    {
        if (empty(trim($buffer))) {
            return $buffer;
        }

        $targetLanguage = UNIVERSALLY_CURRENT_LOCALE;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw wraps the full URL; sanitize_text_field would corrupt percent-encoded UTF-8.
        $sourceUrl = is_404() ? home_url('/404') : esc_url_raw(home_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/')));

        try {
            $response = $this->translate($buffer, $targetLanguage, $sourceUrl);

            if (!empty($response['success']) && !empty($response['data']['translatedHtml'])) {
                $this->handleLimitReached($response['data']['metadata'] ?? []);
                return $response['data']['translatedHtml'];
            }

            Log::debug('Translation response did not contain translated HTML', [
                'url' => $sourceUrl,
                'response' => $response,
            ]);
            return $buffer;
        } catch (\Exception $e) {
            Log::exception($e, 'Translation failed');
            return $buffer;
        }
    }

    private function handleLimitReached(array $metadata): void
    {
        if (!empty($metadata['limitReached'])) {
            set_transient('universally_limit_reached', true, 15 * MINUTE_IN_SECONDS);
        } else {
            delete_transient('universally_limit_reached');
        }
    }

    /**
     * @return string|false
     */
    private function resolveUrlCodeToLocale(string $urlCode)
    {
        $allLanguages = $this->targetLanguages();

        if (empty($allLanguages)) {
            return false;
        }

        foreach ($allLanguages as $language) {
            if (isset($language['urlPrefix']) && $language['urlPrefix'] === $urlCode && empty($language['isDisabled'])) {
                return $language['variant'] ?? false;
            }
        }

        return false;
    }
}
