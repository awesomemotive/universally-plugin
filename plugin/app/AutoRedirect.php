<?php
/**
 * Auto-Redirect
 *
 * Emits the visitor-side runtime that auto-redirects first-time visitors to
 * the language version matching their browser locale. Configuration lives in
 * the Universally dashboard; this class is purely the on-site execution layer.
 *
 * @package Universally
 */

namespace Universally;

if (!defined('ABSPATH')) {
    exit;
}

class AutoRedirect
{
    public function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueRuntime']);
    }

    /**
     * Registers the runtime script (loaded in <head>) and inlines the
     * window.universallyAuto boot object before it. The script is a no-op
     * when auto-redirect is disabled for the site, so we can enqueue it
     * unconditionally except for excluded pages.
     */
    public function enqueueRuntime(): void
    {
        if ($this->isCurrentPageExcluded()) {
            return;
        }

        $config = $this->loadConfig();
        // Skip entirely when disabled, or when there's nothing to match against
        // (no mappings AND no target languages).
        if (
            empty($config)
            || empty($config['enabled'])
            || (empty($config['mappings']) && empty($config['languages']))
        ) {
            return;
        }

        $publicKey = universally_get_public_api_key();
        if (empty($publicKey)) {
            return;
        }

        $handle = 'universally-auto-redirect';
        $bootData = [
            'enabled'        => true,
            'skipBots'       => !empty($config['skipBots']),
            'currentLang'    => universally_get_current_lang(),
            'mappings'       => $config['mappings'],
            'languages'      => $config['languages'],
            'basePath'       => $this->currentBasePath(),
            'beaconEndpoint' => UNIVERSALLY_API_URL . '/connect/redirect-event',
            // The API expects browser-side keys in the prefixed `pk_<hex>` form;
            // the helper returns just the raw 32-char chunk.
            'publicKey'      => 'pk_' . $publicKey,
        ];

        // Register first so the inline-before script attaches to a known handle.
        wp_register_script(
            $handle,
            UNIVERSALLY_PLUGIN_URI . 'assets/js/auto-redirect.js',
            [],
            UNIVERSALLY_VERSION,
            false  // load in <head>, not footer
        );

        wp_add_inline_script(
            $handle,
            'window.universallyAuto = ' . wp_json_encode($bootData) . ';',
            'before'
        );

        wp_enqueue_script($handle);
    }

    /**
     * Pulls the cached site-config transient and shapes the autoRedirect block
     * for inlining into window.universallyAuto. Returns an empty array when
     * the feature is unavailable.
     *
     * Goes through `universally_get_auto_redirect_config()` so the 15-min
     * site-config transient is reused — otherwise this fires on every page
     * render and each one blocks for an API round-trip.
     *
     * @return array{
     *     enabled:bool,
     *     skipBots:bool,
     *     mappings:array<int, array{sourceLocale:string, targetUrlPrefix:string}>,
     *     languages:array<int, array{region:string, urlPrefix:string, lang:string}>
     * }|array{}
     */
    private function loadConfig(): array
    {
        $auto = universally_get_auto_redirect_config();
        if (!is_array($auto) || empty($auto)) {
            return [];
        }

        // Mappings: [{sourceLocale, targetUrlPrefix}, ...]
        $mappings = [];
        $rawMappings = $auto['mappings'] ?? [];
        if (is_array($rawMappings)) {
            foreach ($rawMappings as $m) {
                if (!is_array($m)) continue;
                $source = isset($m['sourceLocale']) ? strtolower((string) $m['sourceLocale']) : '';
                $target = isset($m['targetUrlPrefix']) ? (string) $m['targetUrlPrefix'] : '';
                if ($source !== '' && $target !== '') {
                    $mappings[] = ['sourceLocale' => $source, 'targetUrlPrefix' => $target];
                }
            }
        }

        // Enabled target languages for the runtime's RFC 4647 fallback match.
        // urlPrefix is lowercased so the JS runtime's `currentLangFromPath`
        // (which lowercases the URL segment) compares apples to apples even
        // when the dashboard stores a mixed-case prefix.
        $languages = [];
        $rawLangs = $auto['languages'] ?? [];
        if (is_array($rawLangs)) {
            foreach ($rawLangs as $l) {
                if (!is_array($l)) continue;
                $region = isset($l['region']) ? strtolower((string) $l['region']) : '';
                $url    = isset($l['urlPrefix']) ? strtolower((string) $l['urlPrefix']) : '';
                $lang   = isset($l['lang']) ? strtolower((string) $l['lang']) : '';
                if ($region !== '' && $url !== '' && $lang !== '') {
                    $languages[] = ['region' => $region, 'urlPrefix' => $url, 'lang' => $lang];
                }
            }
        }

        return [
            'enabled'   => !empty($auto['enabled']),
            'skipBots'  => $auto['skipBots'] ?? true,
            'mappings'  => $mappings,
            'languages' => $languages,
        ];
    }

    /**
     * Returns the request path with any current language prefix stripped, so
     * the runtime can build target URLs as `/{newPrefix}{basePath}`.
     *
     * Example: on `/fr/about?ref=x`, with currentLang=`fr`, returns `/about`.
     * Query string is stripped — the redirect target uses path only.
     */
    private function currentBasePath(): string
    {
        $raw = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
        $path = wp_parse_url($raw, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        $currentLang = universally_get_current_lang();
        if (is_string($currentLang) && $currentLang !== '') {
            $stripped = preg_replace(
                '#^/' . preg_quote($currentLang, '#') . '(/|$)#',
                '/',
                $path
            );
            if (is_string($stripped) && $stripped !== '') {
                $path = $stripped;
            }
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * Mirrors LanguageSwitcher::isCurrentPageExcluded(). Lets sites turn off
     * the runtime entirely on checkout pages, etc.
     */
    private function isCurrentPageExcluded(): bool
    {
        $excludePages = universally_get_exclude_pages();
        if (empty($excludePages)) {
            return false;
        }

        $currentPath = wp_parse_url(
            sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '/')),
            PHP_URL_PATH
        );
        $currentPath = rtrim($currentPath ?? '/', '/');

        $currentLang = universally_get_current_lang();
        if ($currentLang !== null) {
            $currentPath = preg_replace(
                '#^/' . preg_quote($currentLang, '#') . '(/|$)#',
                '/',
                $currentPath
            );
            $currentPath = rtrim($currentPath, '/');
        }

        foreach ($excludePages as $pattern) {
            $p = trim($pattern);
            if ($p === '') continue;

            if (substr($p, -2) === '/*') {
                $prefix = rtrim(substr($p, 0, -2), '/');
                if ($currentPath === $prefix || strpos($currentPath, $prefix . '/') === 0) {
                    return true;
                }
            } else {
                if ($currentPath === rtrim($p, '/')) {
                    return true;
                }
            }
        }

        return false;
    }
}
