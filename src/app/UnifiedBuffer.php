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
        if (!isset($_SERVER['REQUEST_METHOD']) || 'GET' !== $_SERVER['REQUEST_METHOD'] || is_admin()) {
            return;
        }

        $detected = $this->detectLanguage();

        if ($detected === false) {
            return;
        }

        [$langCode, $targetLocale, $pathAfterPrefix] = $detected;

        // Redirect /lang to /lang/ (add trailing slash)
        if ($pathAfterPrefix === null) {
            $this->redirectToTrailingSlash($langCode);
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
        $requestUri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        $parsedUri = wp_parse_url($requestUri);
        $path = $parsedUri['path'] ?? '/';

        if (!preg_match('/^\/([a-z0-9-]{2,6})(\/.*)?$/i', $path, $matches)) {
            return false;
        }

        $langCode = strtolower($matches[1]);
        $targetLocale = $this->resolveUrlCodeToLocale($langCode);

        if ($targetLocale === false) {
            return false;
        }

        $pathAfterPrefix = !empty($matches[2]) ? $matches[2] : null;

        return [$langCode, $targetLocale, $pathAfterPrefix];
    }

    private function redirectToTrailingSlash(string $langCode): void
    {
        $redirectUrl = '/' . $langCode . '/';
        if (!empty($_SERVER['QUERY_STRING'])) {
            $redirectUrl .= '?' . sanitize_text_field(wp_unslash($_SERVER['QUERY_STRING']));
        }
        wp_safe_redirect(home_url($redirectUrl), 301);
        exit;
    }

    private function stripLanguagePrefix(string $pathAfterPrefix): void
    {
        $newRequestUri = $pathAfterPrefix;
        if (!empty($_SERVER['QUERY_STRING'])) {
            $newRequestUri .= '?' . sanitize_text_field(wp_unslash($_SERVER['QUERY_STRING']));
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
        $sourceUrl = is_404() ? home_url('/404') : home_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '/')));

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
