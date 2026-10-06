<?php
/**
 * REST API endpoints for admin dashboard
 *
 * @package Universally
 */

namespace Universally;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

class RestApi
{

    private const NAMESPACE = 'universally/v1';

    /** User meta flag: the General tab hero card was dismissed. */
    public const HERO_DISMISSED_META = 'universally_general_hero_dismissed';
    private Http $http;

    public function __construct()
    {
        $this->http = new Http();
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * Register all REST API routes
     *
     * @return void
     */
    public function registerRoutes(): void
    {
        // Validate API key (GET to re-validate, POST to activate/deactivate)
        register_rest_route(self::NAMESPACE, '/validate-api-key', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getApiKey'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'postApiKey'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
        ]);

        // Get languages (GET) / add a target language (POST)
        register_rest_route(self::NAMESPACE, '/languages', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getLanguages'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'addLanguage'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
        ]);

        // Dismiss the General tab hero card for the current user (landing screen
        // shown while the site isn't connected).
        register_rest_route(self::NAMESPACE, '/dismiss-general-hero', [
            'methods' => 'POST',
            'callback' => [$this, 'dismissGeneralHero'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        // Refresh languages cache (called by API server)
        register_rest_route(self::NAMESPACE, '/refresh-languages', [
            'methods' => 'POST',
            'callback' => [$this, 'refreshLanguages'],
            'permission_callback' => [$this, 'checkSecretKey'],
        ]);

        // Refresh site config cache (called by API server)
        register_rest_route(self::NAMESPACE, '/refresh-site-config', [
            'methods' => 'POST',
            'callback' => [$this, 'refreshSiteConfig'],
            'permission_callback' => [$this, 'checkSecretKey'],
        ]);

        // Connection check (called by the app to verify the plugin is connected).
        // Authenticated server-to-server with the site's private key.
        register_rest_route(self::NAMESPACE, '/ping', [
            'methods' => 'GET',
            'callback' => [$this, 'ping'],
            'permission_callback' => [$this, 'checkSecretKey'],
        ]);

    }

    /**
     * GET /ping — confirm the plugin is installed and holds a matching key.
     * The secret-key permission check is the actual verification; reaching this
     * means the app's key matches the one stored here.
     *
     * @return WP_REST_Response
     */
    public function ping(): WP_REST_Response
    {
        return new WP_REST_Response([
            'success'   => true,
            'connected' => true,
            'version'   => defined('UNIVERSALLY_VERSION') ? UNIVERSALLY_VERSION : null,
        ], 200);
    }

    /**
     * Check if user has permission to access endpoints
     *
     * @return bool True if user can manage options
     */
    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Check if request has a valid secret key (for server-to-server calls)
     *
     * @param WP_REST_Request $request Request object
     * @return bool True if secret key is valid
     */
    public function checkSecretKey(WP_REST_Request $request): bool
    {
        $secretKey = $request->get_header('X-Secret-Key');
        $storedKey = universally_get_private_api_key();

        return !empty($secretKey) && !empty($storedKey) && hash_equals($storedKey, $secretKey);
    }

    /**
     * Get languages
     *
     * @return WP_REST_Response
     */
    public function getLanguages(): WP_REST_Response
    {
        $languages = universally_get_all_languages(true);

        return new WP_REST_Response([
            'success' => true,
            'languages' => $languages
        ], 200);
    }

    /**
     * POST /languages — add a target language by variant code.
     *
     * Proxies to the Universally API (POST /connect/languages) with the stored
     * key. On success the languages cache is invalidated and the fresh list is
     * returned so the panel updates without a manual refresh. On failure the
     * API's error code is passed through (e.g. PLAN_LIMIT_REACHED) so the UI can
     * react — always with a 200 status so the client reads the body rather than
     * throwing.
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function addLanguage(WP_REST_Request $request): WP_REST_Response
    {
        $body    = $request->get_json_params();
        $variant = is_array($body) ? trim((string) ($body['variant'] ?? '')) : '';

        if ($variant === '') {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('A language is required.', 'universally-language-translation-multilingual-tool'),
            ], 200);
        }

        $key = universally_get_api_key();
        if ($key === '') {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Your site is not connected to Universally.', 'universally-language-translation-multilingual-tool'),
            ], 200);
        }

        $response = $this->http->post('/connect/languages', ['variant' => $variant], ['X-API-Key' => $key]);

        if (!is_array($response) || empty($response['success'])) {
            return new WP_REST_Response([
                'success' => false,
                'code'    => is_array($response) ? ($response['code'] ?? '') : '',
                'message' => (is_array($response) && !empty($response['message']))
                    ? $response['message']
                    : __('Could not add the language. Please try again.', 'universally-language-translation-multilingual-tool'),
            ], 200);
        }

        // Refresh the cached list so the table reflects the new language immediately.
        delete_transient('universally_all_languages');
        $languages = universally_get_all_languages(true);

        return new WP_REST_Response([
            'success'   => true,
            'message'   => $response['message'] ?? __('Language added.', 'universally-language-translation-multilingual-tool'),
            'languages' => $languages,
        ], 200);
    }

    /**
     * POST /dismiss-general-hero — hide the General tab hero card for this user
     *
     * Stored per user, so one admin closing it doesn't hide it for the others.
     *
     * @return WP_REST_Response
     */
    public function dismissGeneralHero(): WP_REST_Response
    {
        update_user_meta(get_current_user_id(), self::HERO_DISMISSED_META, 1);

        return new WP_REST_Response(['success' => true], 200);
    }

    /**
     * GET /validate-api-key — status of the stored API key
     *
     * Served from the 5-minute key-status cache unless `fresh=1` is passed
     * (the "Check again" button), which re-verifies with the API and stores
     * the new answer. `valid`, `message` and `value` keep their original
     * meaning; `status` (none|valid|rejected|unknown), `code` and `checked_at`
     * were added for the broken-connection screens.
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function getApiKey(WP_REST_Request $request): WP_REST_Response
    {
        $key = universally_get_api_key();

        if ($key === '') {
            return new WP_REST_Response([
                'valid'      => false,
                'message'    => '',
                'status'     => KeyStatus::NONE,
                'code'       => '',
                'checked_at' => 0,
            ], 200);
        }

        $fresh  = rest_sanitize_boolean($request->get_param('fresh'));
        $result = KeyStatus::get($fresh, $this->http);

        return new WP_REST_Response([
            'valid'      => $result['valid'],
            'message'    => $result['message'],
            'value'      => $this->maskKey($key),
            'status'     => $result['status'],
            'code'       => $result['code'],
            'checked_at' => $result['checked_at'],
        ], 200);
    }

    /**
     * POST /validate-api-key — activate or deactivate an API key
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function postApiKey(WP_REST_Request $request): WP_REST_Response
    {
        $body   = $request->get_json_params();
        $action = $body['action'] ?? null;

        if ($action === 'deactivate') {
            // Tell the app we're disconnecting (best-effort) so the dashboard
            // stops showing the site as connected. Must run while we still hold
            // the key, before we forget it below.
            $key = universally_get_api_key();
            if ($key !== '') {
                $this->http->post('/connect/disconnect', [], ['X-API-Key' => $key]);
            }

            delete_option('universally_api_key');
            delete_transient('universally_site_config');
            delete_transient('universally_all_languages');

            return new WP_REST_Response([
                'valid'   => false,
                'message' => __('API key deactivated.', 'universally-language-translation-multilingual-tool'),
            ], 200);
        }

        $value = $body['value'] ?? '';

        if (empty($value)) {
            return new WP_REST_Response([
                'valid'   => false,
                'message' => __('API key is required.', 'universally-language-translation-multilingual-tool'),
            ], 200);
        }

        if (!preg_match('/^[0-9a-f]{64}$/i', $value)) {
            return new WP_REST_Response([
                'valid'   => false,
                'message' => __('API key format is invalid.', 'universally-language-translation-multilingual-tool'),
            ], 200);
        }

        $result = $this->verifyKey($value);

        if ($result['valid']) {
            update_option('universally_api_key', $value);
            // The option hooks just cleared the key-status cache; seed it with
            // this answer so the reload doesn't verify the same key again.
            KeyStatus::remember($value, $result);
            // Drop caches built for the previous connection (or the empty
            // language list cached while disconnected).
            delete_transient('universally_site_config');
            delete_transient('universally_all_languages');
        }

        return new WP_REST_Response([
            'valid'   => $result['valid'],
            'message' => $result['message'],
        ], 200);
    }

    /**
     * POST /refresh-languages — refresh languages cache, validated by secret key
     */
    public function refreshLanguages(): WP_REST_Response
    {
        $languages = universally_get_all_languages(true);

        return new WP_REST_Response(['success' => true, 'languages' => $languages], 200);
    }

    /**
     * POST /refresh-site-config — refresh site config cache, validated by secret key
     */
    public function refreshSiteConfig(): WP_REST_Response
    {
        universally_get_exclude_pages(true);

        return new WP_REST_Response(['success' => true], 200);
    }

    /**
     * Verify an API key against the Universally API (uncached)
     *
     * @param string $key The API key to verify
     * @return array{valid: bool, message: string, code: string, status: string, checked_at: int}
     */
    private function verifyKey(string $key): array
    {
        return KeyStatus::verify($key, $this->http);
    }

    /**
     * Mask a key for display: first 4 + asterisks + last 4
     *
     * @param string $key The key to mask
     * @return string Masked key
     */
    private function maskKey(string $key): string
    {
        if (strlen($key) <= 8) {
            return $key;
        }

        return substr($key, 0, 4) . str_repeat('*', 56) . substr($key, -4);
    }

}
