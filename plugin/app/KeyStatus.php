<?php
/**
 * Stored API key status, as last reported by the Universally API
 *
 * Resolves the stored key to one of four states:
 *  - none:     no key stored
 *  - valid:    the API accepted the key (KEY_VALID)
 *  - rejected: the API gave a definite no (invalid key, bad format, site deleted)
 *  - unknown:  the API couldn't be reached or answered with an unexpected code
 *
 * The answer is cached for a few minutes in a transient bound to the key it was
 * computed for (a hash of the key is stored alongside), and dropped whenever the
 * `universally_api_key` option is added, updated or deleted.
 *
 * @package Universally
 */

namespace Universally;

if (!defined('ABSPATH')) {
    exit;
}

class KeyStatus
{
    public const NONE     = 'none';
    public const VALID    = 'valid';
    public const REJECTED = 'rejected';
    public const UNKNOWN  = 'unknown';

    public const TRANSIENT = 'universally_key_status';

    private const OPTION = 'universally_api_key';

    /** API codes that mean "this key will not work" (vs. a transient failure). */
    private const REJECTED_CODES = ['API_KEY_INVALID', 'API_KEY_INVALID_FORMAT', 'SITE_IS_DELETED'];

    /**
     * Drop the cached status whenever the stored key changes.
     *
     * @return void
     */
    public static function registerHooks(): void
    {
        add_action('add_option_' . self::OPTION, [self::class, 'clear']);
        add_action('update_option_' . self::OPTION, [self::class, 'clear']);
        add_action('delete_option_' . self::OPTION, [self::class, 'clear']);
    }

    /**
     * @return void
     */
    public static function clear(): void
    {
        delete_transient(self::TRANSIENT);
    }

    /**
     * Status of the stored key, from the cache when possible.
     *
     * @param bool      $fresh Skip the cache and ask the API now (result is still cached).
     * @param Http|null $http  Client to use for the API call.
     * @return array{status: string, valid: bool, code: string, message: string, checked_at: int}
     */
    public static function get(bool $fresh = false, ?Http $http = null): array
    {
        $key = universally_get_api_key();
        if ($key === '') {
            return self::result(self::NONE, '', '', 0);
        }

        $hash = self::hashKey($key);

        if (!$fresh) {
            $cached = get_transient(self::TRANSIENT);
            if (
                is_array($cached)
                && isset($cached['key_hash'], $cached['status'])
                && is_string($cached['key_hash'])
                && hash_equals($cached['key_hash'], $hash)
            ) {
                return self::result(
                    (string) $cached['status'],
                    (string) ($cached['code'] ?? ''),
                    (string) ($cached['message'] ?? ''),
                    (int) ($cached['checked_at'] ?? 0)
                );
            }
        }

        $result = self::verify($key, $http);
        self::remember($key, $result);

        return $result;
    }

    /**
     * Cache a verification result for the given key.
     *
     * @param string $key    The key the result belongs to.
     * @param array  $result A result from verify().
     * @return void
     */
    public static function remember(string $key, array $result): void
    {
        set_transient(self::TRANSIENT, $result + ['key_hash' => self::hashKey($key)], 5 * MINUTE_IN_SECONDS);
    }

    /**
     * Status assumed without calling the API: `valid` when a key is stored.
     *
     * Used outside the settings page (front end, REST saves) so those requests
     * never wait on the API and always see the normal connected schema.
     *
     * @return array{status: string, valid: bool, code: string, message: string, checked_at: int}
     */
    public static function assumed(): array
    {
        return universally_get_api_key() === ''
            ? self::result(self::NONE, '', '', 0)
            : self::result(self::VALID, '', '', 0);
    }

    /**
     * Verify a key against the Universally API (uncached).
     *
     * @param string    $key  The API key to verify.
     * @param Http|null $http Client to use for the API call.
     * @return array{status: string, valid: bool, code: string, message: string, checked_at: int}
     */
    public static function verify(string $key, ?Http $http = null): array
    {
        // Short default timeout: the settings page waits on this before it
        // renders, so an API outage must not hang it for the 30s default.
        $http     = $http ?? new Http(null, 5);
        $response = $http->get('/connect/keys/verify', [
            'X-API-Key' => $key,
        ]);

        if ($response === false) {
            Log::error('API key verification failed: cURL error');
        }

        $code = is_array($response) && isset($response['code']) && is_string($response['code'])
            ? $response['code']
            : '';

        $status = self::classify($response === false ? null : $code);

        return self::result($status, $code, self::message($status, $code, $response === false), time());
    }

    /**
     * Map an API code to a status. `null` means the request itself failed.
     *
     * @param string|null $code
     * @return string One of the status constants (never NONE).
     */
    public static function classify(?string $code): string
    {
        if ($code === null) {
            return self::UNKNOWN;
        }
        if ($code === 'KEY_VALID') {
            return self::VALID;
        }
        if (in_array($code, self::REJECTED_CODES, true)) {
            return self::REJECTED;
        }
        return self::UNKNOWN;
    }

    /**
     * Short masked key for display, e.g. "a3f9••••••••c21e".
     *
     * @param string $key
     * @return string
     */
    public static function shortMask(string $key): string
    {
        if (strlen($key) <= 8) {
            return str_repeat('•', strlen($key));
        }

        return substr($key, 0, 4) . str_repeat('•', 8) . substr($key, -4);
    }

    /**
     * "checked 4 mins ago" / "checked just now" for a check timestamp.
     *
     * @param int $checkedAt Unix timestamp.
     * @return string
     */
    public static function checkedLabel(int $checkedAt): string
    {
        $diff = time() - $checkedAt;
        if ($checkedAt <= 0 || $diff < MINUTE_IN_SECONDS) {
            return __('checked just now', 'universally-language-translation-multilingual-tool');
        }

        /* translators: %s: human-readable time difference, e.g. "4 mins". */
        return sprintf(__('checked %s ago', 'universally-language-translation-multilingual-tool'), human_time_diff($checkedAt));
    }

    /**
     * "Last checked 4 mins ago" / "Last checked just now" (banner wording).
     *
     * @param int $checkedAt Unix timestamp.
     * @return string
     */
    public static function lastCheckedLabel(int $checkedAt): string
    {
        $diff = time() - $checkedAt;
        if ($checkedAt <= 0 || $diff < MINUTE_IN_SECONDS) {
            return __('Your site keeps its current setup. Last checked just now.', 'universally-language-translation-multilingual-tool');
        }

        /* translators: %s: human-readable time difference, e.g. "4 mins". */
        return sprintf(__('Your site keeps its current setup. Last checked %s ago.', 'universally-language-translation-multilingual-tool'), human_time_diff($checkedAt));
    }

    /**
     * User-facing message for a verification outcome.
     *
     * @param string $status
     * @param string $code
     * @param bool   $networkFailed
     * @return string
     */
    private static function message(string $status, string $code, bool $networkFailed): string
    {
        if ($networkFailed) {
            return __('Could not connect to the API server. Please try again later.', 'universally-language-translation-multilingual-tool');
        }

        if ($status === self::VALID) {
            return __('API key is valid.', 'universally-language-translation-multilingual-tool');
        }

        switch ($code) {
            case 'API_KEY_INVALID':
                return __('API key is invalid.', 'universally-language-translation-multilingual-tool');
            case 'API_KEY_INVALID_FORMAT':
                return __('API key format is invalid.', 'universally-language-translation-multilingual-tool');
            case 'SITE_IS_DELETED':
                return __('The site associated with this key has been deleted.', 'universally-language-translation-multilingual-tool');
        }

        return __('Could not verify API key. Please try again.', 'universally-language-translation-multilingual-tool');
    }

    /**
     * @return array{status: string, valid: bool, code: string, message: string, checked_at: int}
     */
    private static function result(string $status, string $code, string $message, int $checkedAt): array
    {
        return [
            'status'     => $status,
            'valid'      => $status === self::VALID,
            'code'       => $code,
            'message'    => $message,
            'checked_at' => $checkedAt,
        ];
    }

    /**
     * @param string $key
     * @return string
     */
    private static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }
}
