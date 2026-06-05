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
    private const SWITCH_PARAM = 'universally_switch';

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
        $detected = $this->detectLanguage();

        // For non-GET requests that don't carry the prefix in the URL (typical for
        // form POSTs whose action came from get_permalink()), fall back to the referer
        // so we can still translate the rendered response.
        if ($detected === false && !$isGet) {
            $refererLang = $this->detectLanguageFromReferer();
            if ($refererLang === false) {
                return;
            }
            $refererLocale = $this->resolveUrlCodeToLocale($refererLang);
            if ($refererLocale === false) {
                return;
            }
            $langCode = $refererLang;
            $targetLocale = $refererLocale;
            $pathAfterPrefix = null;
        } elseif ($detected === false) {
            // GET without a URL prefix. An explicit ?universally_switch=source marker
            // (added by the switcher to the source-language link) means the visitor
            // opted back into the source language: clear the stored preference with
            // the same cookie attributes used to set it, then redirect to the clean
            // URL. Handled server-side so it works even when the switcher's click
            // handler doesn't run (new tab, prefetch, cookie path/domain mismatch).
            if ($this->isSourceSwitchRequest()) {
                $this->clearLanguageCookie();
                $this->redirectToCleanUrl();
            }
            // Otherwise honor the visitor's stored preference.
            $preferredLang = $this->getPreferredLanguageFromCookie();
            if ($preferredLang !== null) {
                $this->redirectToPreferredLanguage($preferredLang);
            }
            return;
        } else {
            [$langCode, $targetLocale, $pathAfterPrefix] = $detected;
        }

        // GET-only navigation behaviors: trailing slash normalization, cookie
        // persistence, and bouncing /{lang}/excluded/ paths to the source URL.
        if ($isGet) {
            if ($pathAfterPrefix === null) {
                $this->redirectToTrailingSlash($langCode);
            }
            $this->setLanguageCookie($langCode);
            if (universally_path_is_excluded($pathAfterPrefix)) {
                $this->redirectToSource($pathAfterPrefix);
            }
        }

        define('UNIVERSALLY_CURRENT_LANG', $langCode);
        define('UNIVERSALLY_CURRENT_LOCALE', $targetLocale);

        if ($pathAfterPrefix !== null) {
            $this->stripLanguagePrefix($pathAfterPrefix);
        }
        $this->preserveLanguagePrefixOnRedirects($langCode);
        $this->preserveLanguagePrefixOnWooCommerceUrls($langCode);

        // Don't capture responses from endpoints that return JSON/XML — translating
        // those bodies as HTML would corrupt the response. Frontend HTML rendered as
        // a POST response (e.g. WC add-to-cart with default settings) still flows
        // through translateBuffer, with a Content-Type guard as defense in depth.
        if ($this->isNonHtmlEndpoint()) {
            return;
        }

        ob_start([$this, 'translateBuffer']);
    }

    private function isNonHtmlEndpoint(): bool
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }
        if (wp_doing_ajax()) {
            return true;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed by wp_parse_url; sanitize_text_field would corrupt percent-encoded UTF-8 in non-Latin slugs.
        $requestUri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = (string) wp_parse_url($requestUri, PHP_URL_PATH);
        if ($path !== '' && preg_match('#^/(wp-json|xmlrpc\.php|wp-trackback\.php)(/|$)#', $path)) {
            return true;
        }
        // WooCommerce AJAX (?wc-ajax=...) bypasses admin-ajax and returns JSON.
        // Nonce verification is irrelevant here — we're deciding whether to attach an
        // output buffer based on the presence of a query parameter, not processing it.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['wc-ajax'])) {
            return true;
        }
        return false;
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

        $existing = isset($_COOKIE[self::LANG_COOKIE])
            ? sanitize_key(wp_unslash((string) $_COOKIE[self::LANG_COOKIE]))
            : '';
        if ($existing === $langCode) {
            return;
        }

        setcookie(self::LANG_COOKIE, $langCode, $this->languageCookieOptions(time() + 30 * DAY_IN_SECONDS));
        // Reflect into $_COOKIE so later code in this request sees the new value.
        $_COOKIE[self::LANG_COOKIE] = $langCode;
    }

    /**
     * Expire the preference cookie using the exact same path/domain attributes it
     * was set with — browsers only match a deletion against an identical cookie.
     */
    private function clearLanguageCookie(): void
    {
        if (!headers_sent()) {
            setcookie(self::LANG_COOKIE, '', $this->languageCookieOptions(time() - YEAR_IN_SECONDS));
        }
        unset($_COOKIE[self::LANG_COOKIE]);
    }

    private function languageCookieOptions(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'domain' => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure' => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ];
    }

    private function isSourceSwitchRequest(): bool
    {
        // Navigational opt-out marker, not a state-changing form action — nonce
        // verification doesn't apply (worst case a crafted link clears a cookie).
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET[self::SWITCH_PARAM])) {
            return false;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return sanitize_key(wp_unslash((string) $_GET[self::SWITCH_PARAM])) === 'source';
    }

    /**
     * Redirect to the current URL with the ?universally_switch marker removed,
     * keeping the rest of the query string byte-for-byte (no re-encoding that
     * could corrupt percent-encoded UTF-8).
     */
    private function redirectToCleanUrl(): void
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed by wp_parse_url; sanitize_text_field would corrupt percent-encoded UTF-8.
        $requestUri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $parsed = wp_parse_url($requestUri);
        $path = $parsed['path'] ?? '/';

        $query = '';
        if (!empty($parsed['query'])) {
            $pairs = array_filter(explode('&', $parsed['query']), function (string $pair): bool {
                return $pair !== self::SWITCH_PARAM && strpos($pair, self::SWITCH_PARAM . '=') !== 0;
            });
            if (!empty($pairs)) {
                $query = '?' . implode('&', $pairs);
            }
        }

        wp_safe_redirect(esc_url_raw(home_url($path . $query)), 302);
        exit;
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
            return $this->prefixUrlWithLanguage($location, $langCode);
        });
    }

    /**
     * Ensure same-origin form actions / outbound URLs WC emits carry the language
     * prefix, so the resulting POST or navigation stays in the visitor's language.
     */
    private function preserveLanguagePrefixOnWooCommerceUrls(string $langCode): void
    {
        $filter = function (string $url) use ($langCode): string {
            return $this->prefixUrlWithLanguage($url, $langCode);
        };

        // Form action for the single-product add-to-cart form (the case where
        // submitting otherwise drops the visitor to the unprefixed product URL).
        add_filter('woocommerce_add_to_cart_form_action', $filter);
    }

    /**
     * Prefix a same-origin URL with /{langCode}/. Returns the URL unchanged when
     * it points at another host, hits a WordPress system path, or already carries
     * a valid language prefix.
     */
    private function prefixUrlWithLanguage(string $url, string $langCode): string
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
        if ($firstSegment !== '' && $this->resolveUrlCodeToLocale($firstSegment) !== false) {
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

    public function translateBuffer(string $buffer): string
    {
        if (empty(trim($buffer))) {
            return $buffer;
        }

        // Defense in depth: if some handler short-circuited and emitted a non-HTML
        // response (JSON, XML, plain text), don't hand it to the HTML translator.
        foreach (headers_list() as $header) {
            if (stripos($header, 'content-type:') !== 0) {
                continue;
            }
            if (stripos($header, 'text/html') === false) {
                return $buffer;
            }
            break;
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
