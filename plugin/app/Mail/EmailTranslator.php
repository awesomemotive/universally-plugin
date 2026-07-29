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
     * Hard cap per HTTP call while a customer is waiting on the request that
     * sends the email (a classic or Store API checkout).
     *
     * Measured against a real WooCommerce email over 18 requests: p50 1.8s,
     * p90 6.1s, tail 12.3s. No single cap removes the tail — it only trades
     * checkout latency for untranslated emails — so cap the blocking case
     * here and outwait the tail in TIMEOUT_BACKGROUND below.
     */
    private const TIMEOUT = 10;

    /**
     * Cap when nobody is waiting: the send runs from cron or Action Scheduler
     * (WooCommerce's deferred transactional emails), so covering Gemini's tail
     * costs no one anything.
     */
    private const TIMEOUT_BACKGROUND = 20;

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

        // A 200 does not mean anything was translated: when the AI batch fails
        // or every string is skipped, the worker echoes the original HTML back.
        // Treat that as failure so the caller doesn't ship a "translated" body
        // that is still in the source language.
        if (empty($data['metadata']['stringsTranslated'])) {
            return null;
        }

        return $data['translatedHtml'] ?? null;
    }

    /**
     * How long to wait on the translator, based on whether a customer is
     * blocked on this request.
     */
    private function timeout(): int
    {
        $background = wp_doing_cron()
            || did_action('action_scheduler_begin_execute')
            || (defined('WP_CLI') && WP_CLI);

        return $background ? self::TIMEOUT_BACKGROUND : self::TIMEOUT;
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

        $http = new Http(UNIVERSALLY_TRANSLATOR_URL, $this->timeout());
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
