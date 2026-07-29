<?php
/**
 * Client for translating email content via the Universally translator worker.
 *
 * Both methods fail soft: any transport error, API error, or word-limit
 * condition returns null so the caller can send the untranslated original.
 *
 * @package Universally
 */

namespace Universally\Mail;

use Universally\Http;
use Universally\Log;

if (!defined('ABSPATH')) {
    exit;
}

class EmailTranslator
{
    /**
     * Hard cap per HTTP call so translation never stalls an email send.
     *
     * Measured: a cached template comes back in ~50ms, a cold one needs a
     * Gemini roundtrip at 2.5-4s — 5s trips on cold sends, which would keep
     * a site that never warms its cache permanently untranslated.
     */
    private const TIMEOUT = 10;

    /**
     * Translate plain-text strings. Returns an original => translated map.
     *
     * Strings the translator skips as non-translatable (URLs, bare numbers)
     * are absent from the map — callers should fall back to the original
     * per string. Returns null on failure or when the word limit is reached.
     *
     * @param string[] $strings
     * @return array<string, string>|null
     */
    public function translateStrings(array $strings, string $locale): ?array
    {
        $data = $this->post('/v1/translate/strings', [
            'strings'        => array_values($strings),
            'targetLanguage' => $locale,
        ], false);

        if ($data === null) {
            return null;
        }

        return $data['translations'] ?? null;
    }

    /**
     * Translate a full HTML document (rendered email body).
     *
     * @return string|null Translated HTML, or null on failure/limit.
     */
    public function translateHtml(string $html, string $locale, string $sourceUrl): ?string
    {
        $data = $this->post('/v1/translate', [
            'html'           => $html,
            'targetLanguage' => $locale,
            'sourceUrl'      => $sourceUrl,
        ], true);

        if ($data === null) {
            return null;
        }

        return $data['translatedHtml'] ?? null;
    }

    /**
     * POST to the translator worker and unwrap the response envelope.
     *
     * Returns the `data` payload, or null on any failure. A limitReached
     * response is treated as failure: a partially translated email is worse
     * than an untranslated one.
     *
     * @return array|null
     */
    private function post(string $endpoint, array $body, bool $gzip): ?array
    {
        $apiKey = universally_get_api_key();

        if (empty($apiKey)) {
            return null;
        }

        $http = new Http(UNIVERSALLY_TRANSLATOR_URL, self::TIMEOUT);
        $response = $http->post($endpoint, $body, ['X-API-Key' => $apiKey], $gzip);

        if (!is_array($response) || empty($response['success'])) {
            Log::error('Email translation request failed: ' . $endpoint);
            return null;
        }

        $data = $response['data'] ?? [];

        if (!empty($data['metadata']['limitReached'])) {
            set_transient('universally_limit_reached', true, 15 * MINUTE_IN_SECONDS);
            return null;
        }

        return $data;
    }
}
