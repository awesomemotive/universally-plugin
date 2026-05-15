<?php
/**
 * HTTP client for API communication
 *
 * Uses WordPress HTTP API (wp_remote_request) for reliable DNS resolution
 * and compatibility across hosting environments.
 *
 * @package Universally
 */

namespace Universally;

if (!defined('ABSPATH')) {
    exit;
}

class Http
{
    private string $apiUrl;
    private int $timeout;

    public function __construct(?string $baseUrl = null, int $timeout = 30)
    {
        $this->apiUrl = trailingslashit($baseUrl ?? UNIVERSALLY_API_URL);
        $this->timeout = $timeout;
    }

    /**
     * @return array|false
     */
    public function get(string $endpoint, array $headers = [])
    {
        return $this->request('GET', $endpoint, [], $headers);
    }

    /**
     * @return array|false
     */
    public function post(string $endpoint, array $body = [], array $headers = [], bool $gzip = false)
    {
        return $this->request('POST', $endpoint, $body, $headers, $gzip);
    }

    /**
     * @return array|false
     */
    public function delete(string $endpoint, array $body = [], array $headers = [])
    {
        return $this->request('DELETE', $endpoint, $body, $headers);
    }

    /**
     * @return array|false
     */
    public function patch(string $endpoint, array $body = [], array $headers = [])
    {
        return $this->request('PATCH', $endpoint, $body, $headers);
    }

    /**
     * @return array|false
     */
    private function request(string $method, string $endpoint, array $body = [], array $headers = [], bool $gzip = false)
    {
        $url = $this->apiUrl . ltrim($endpoint, '/');

        $args = [
            'method'    => $method,
            'timeout'   => $this->timeout,
            'sslverify' => true,
            'headers'   => array_merge(
                [
                    'Content-Type' => 'application/json',
                    'User-Agent'   => self::userAgent(),
                ],
                $headers
            ),
        ];

        if (!empty($body)) {
            $jsonBody = wp_json_encode($body);
            if ($jsonBody === false) {
                Log::error('Failed to encode request body');
                return false;
            }

            if ($gzip && function_exists('gzencode')) {
                $compressed = gzencode($jsonBody);
                if ($compressed !== false) {
                    $jsonBody = $compressed;
                    $args['headers']['Content-Encoding'] = 'gzip';
                    // Prevent WordPress from processing binary body
                    $args['decompress'] = false;
                }
            }

            $args['body'] = $jsonBody;
            $args['data_format'] = 'body';
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            Log::error('API request failed: ' . $response->get_error_message());
            return false;
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $responseBody = wp_remote_retrieve_body($response);

        $data = json_decode($responseBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Failed to decode API response: ' . json_last_error_msg());
            return false;
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            Log::error(sprintf(
                'API %s %s failed with status %d: %s',
                $method,
                $endpoint,
                $statusCode,
                mb_substr($responseBody, 0, 500)
            ));
        }

        return $data;
    }

    /**
     * Build the User-Agent string sent with every outbound request.
     * Format: `Universally-WP/<plugin> (WordPress/<wp>; PHP/<php>; <site-url>)`.
     */
    private static function userAgent(): string
    {
        global $wp_version;
        return sprintf(
            'Universally-WP/%s (WordPress/%s; PHP/%s; %s)',
            defined('UNIVERSALLY_VERSION') ? UNIVERSALLY_VERSION : 'unknown',
            is_string($wp_version) && $wp_version !== '' ? $wp_version : 'unknown',
            PHP_VERSION,
            home_url('/')
        );
    }
}
