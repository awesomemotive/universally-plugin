<?php
/**
 * Activation token exchange flow.
 *
 * Three REST endpoints under universally/v1:
 *   POST /activation/exchange  — swap a single-use token for the site's API key (kept server-side)
 *   POST /activation/commit    — persist the held key into wp_options
 *   POST /activation/cancel    — drop the held key
 *
 * The API key never leaves PHP. The browser only ever sees an opaque exchangeId
 * + workspace display info.
 *
 * @package Universally
 */

namespace Universally;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

class ActivationToken
{
    private const NAMESPACE = 'universally/v1';
    private const TRANSIENT_PREFIX = 'universally_activation_';
    private const TRANSIENT_TTL = 600; // 10 minutes — matches the API-side token TTL.

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/activation/exchange', [
            'methods' => 'POST',
            'callback' => [$this, 'exchange'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);
        register_rest_route(self::NAMESPACE, '/activation/commit', [
            'methods' => 'POST',
            'callback' => [$this, 'commit'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);
        register_rest_route(self::NAMESPACE, '/activation/cancel', [
            'methods' => 'POST',
            'callback' => [$this, 'cancel'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    public function exchange(WP_REST_Request $request): WP_REST_Response
    {
        $body  = $request->get_json_params();
        $token = is_array($body) && isset($body['token']) ? (string) $body['token'] : '';

        // Format check before sending it anywhere — keeps obviously-bogus values out of network/log paths.
        if (!preg_match('/^[A-Za-z0-9_\-\.]{16,512}$/', $token)) {
            return new WP_REST_Response([
                'success' => false,
                'code'    => 'ACTIVATION_TOKEN_INVALID',
                'message' => $this->mapErrorMessage('ACTIVATION_TOKEN_INVALID'),
            ], 200);
        }

        $response = (new Http(UNIVERSALLY_API_URL))->post('/connect/activate', [
            'activation_token' => $token,
        ]);

        if ($response === false) {
            // Http already logged the underlying network error.
            return new WP_REST_Response([
                'success' => false,
                'code'    => 'NETWORK_ERROR',
                'message' => $this->mapErrorMessage('NETWORK_ERROR'),
            ], 200);
        }

        if (empty($response['success'])) {
            $code = isset($response['code']) ? (string) $response['code'] : 'UNKNOWN';
            Log::warning('Activation exchange rejected', ['code' => $code]);
            return new WP_REST_Response([
                'success' => false,
                'code'    => $code,
                'message' => $this->mapErrorMessage($code),
            ], 200);
        }

        $data   = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $apiKey = isset($data['apiKey']) ? (string) $data['apiKey'] : '';

        if (!preg_match('/^[0-9a-f]{64}$/i', $apiKey)) {
            Log::warning('Activation exchange returned malformed apiKey');
            return new WP_REST_Response([
                'success' => false,
                'code'    => 'UNKNOWN',
                'message' => $this->mapErrorMessage('UNKNOWN'),
            ], 200);
        }

        $displayInfo = [
            'workspaceName' => isset($data['workspaceName']) ? (string) $data['workspaceName'] : '',
            'ownerEmail'    => isset($data['ownerEmail'])    ? (string) $data['ownerEmail']    : '',
            'siteName'      => isset($data['siteName'])      ? (string) $data['siteName']      : '',
            'siteDomain'    => isset($data['siteDomain'])    ? (string) $data['siteDomain']    : '',
        ];

        $exchangeId = wp_generate_password(32, false);
        $stored = set_transient(self::TRANSIENT_PREFIX . $exchangeId, [
            'apiKey'      => $apiKey,
            'displayInfo' => $displayInfo,
        ], self::TRANSIENT_TTL);

        if (!$stored) {
            Log::warning('Activation exchange failed to store transient');
            return new WP_REST_Response([
                'success' => false,
                'code'    => 'UNKNOWN',
                'message' => $this->mapErrorMessage('UNKNOWN'),
            ], 200);
        }

        return new WP_REST_Response([
            'success'          => true,
            'exchangeId'       => $exchangeId,
            'displayInfo'      => $displayInfo,
            'alreadyConnected' => !empty(get_option('universally_api_key', '')),
        ], 200);
    }

    public function commit(WP_REST_Request $request): WP_REST_Response
    {
        $body       = $request->get_json_params();
        $exchangeId = is_array($body) && isset($body['exchangeId']) ? (string) $body['exchangeId'] : '';

        if (!preg_match('/^[A-Za-z0-9]{32}$/', $exchangeId)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Activation session expired. Generate a new activation link.', 'universally-language-translation-multilingual-tool'),
            ], 200);
        }

        $key   = self::TRANSIENT_PREFIX . $exchangeId;
        $stash = get_transient($key);

        if (!is_array($stash) || empty($stash['apiKey']) || !preg_match('/^[0-9a-f]{64}$/i', (string) $stash['apiKey'])) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Activation session expired. Generate a new activation link.', 'universally-language-translation-multilingual-tool'),
            ], 200);
        }

        update_option('universally_api_key', $stash['apiKey']);
        delete_transient($key);

        return new WP_REST_Response([
            'success'     => true,
            'displayInfo' => isset($stash['displayInfo']) && is_array($stash['displayInfo']) ? $stash['displayInfo'] : [],
        ], 200);
    }

    public function cancel(WP_REST_Request $request): WP_REST_Response
    {
        $body       = $request->get_json_params();
        $exchangeId = is_array($body) && isset($body['exchangeId']) ? (string) $body['exchangeId'] : '';

        if (preg_match('/^[A-Za-z0-9]{32}$/', $exchangeId)) {
            delete_transient(self::TRANSIENT_PREFIX . $exchangeId);
        }

        return new WP_REST_Response(['success' => true], 200);
    }

    private function mapErrorMessage(string $code): string
    {
        switch ($code) {
            case 'ACTIVATION_TOKEN_EXPIRED':
                return __('This activation link has expired. Generate a new one from your Universally dashboard.', 'universally-language-translation-multilingual-tool');
            case 'ACTIVATION_TOKEN_USED':
                return __('This activation link has already been used. Generate a new one if you need to reconnect.', 'universally-language-translation-multilingual-tool');
            case 'ACTIVATION_TOKEN_INVALID':
                return __('This activation link is not valid.', 'universally-language-translation-multilingual-tool');
            case 'NETWORK_ERROR':
            default:
                return __('Could not reach Universally. Try again, or paste your API key manually below.', 'universally-language-translation-multilingual-tool');
        }
    }
}
