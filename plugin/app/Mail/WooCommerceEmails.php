<?php
/**
 * Translates WooCommerce customer emails into the language the order was
 * placed in, at send time, via the woocommerce_mail_callback_params filter
 * (fires in WC_Email::send() with the fully rendered subject and message).
 *
 * Fail-open: any missing signal or translation failure sends the original.
 *
 * @package Universally
 */

namespace Universally\Mail;

if (!defined('ABSPATH')) {
    exit;
}

class WooCommerceEmails
{
    public function __construct()
    {
        // Defer: WooCommerce and multilingual plugins are loaded by now.
        add_action('plugins_loaded', [$this, 'setup'], 20);
    }

    public function setup(): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        // A dedicated multilingual plugin may translate emails itself — step aside.
        if (universally_has_conflicting_multilingual_plugin()) {
            return;
        }

        add_filter('woocommerce_mail_callback_params', [$this, 'translateEmail'], 10, 2);
    }

    /**
     * @param array     $args  [to, subject, message, headers, attachments]
     * @param \WC_Email $email
     * @return array
     */
    public function translateEmail($args, $email): array
    {
        if (!is_array($args) || !universally_translate_emails_enabled()) {
            return $args;
        }

        if (!$email instanceof \WC_Email || !$email->is_customer_email()) {
            return $args;
        }

        $order = $email->object;

        if (!$order instanceof \WC_Order) {
            return $args;
        }

        $locale = OrderLanguage::getOrderLocale($order);

        if ($locale === null) {
            return $args;
        }

        $subject = (string) ($args[1] ?? '');
        $message = (string) ($args[2] ?? '');

        if ($subject === '' || $message === '') {
            return $args;
        }

        $translator = new EmailTranslator();

        // Synthetic source URL: must be a real URL on the site's own domain
        // (the translator validates both), while the `__`-prefixed path keeps
        // email bodies out of the site's page records.
        $sourceUrl = home_url('/__email__/wc/' . $email->id . '/');

        $translatedMessage = $email->get_email_type() === 'plain'
            ? $this->translatePlainBody($translator, $message, $locale)
            : $translator->translateHtml($message, $locale, $sourceUrl);

        if ($translatedMessage === null) {
            return $args;
        }

        $translations = $translator->translateStrings([$subject], $locale);

        // All-or-nothing: a translated body with an untranslated subject (or
        // vice versa) reads worse than a fully untranslated email. A missing
        // key means the worker answered but did not translate this string.
        if ($translations === null || !isset($translations[$subject])) {
            return $args;
        }

        $args[1] = $translations[$subject];
        $args[2] = $translatedMessage;

        return $args;
    }

    /**
     * Plain-text bodies go through the strings endpoint paragraph by
     * paragraph. Paragraphs the translator skips (URLs, plain numbers)
     * stay as-is by design.
     *
     * @return string|null Null = hard failure, caller sends the original.
     */
    private function translatePlainBody(EmailTranslator $translator, string $body, string $locale): ?string
    {
        $paragraphs = explode("\n\n", $body);

        $strings = [];
        foreach ($paragraphs as $paragraph) {
            $trimmed = trim($paragraph);
            if ($trimmed !== '') {
                $strings[] = $trimmed;
            }
        }

        if (empty($strings)) {
            return $body;
        }

        $translations = $translator->translateStrings($strings, $locale);

        if ($translations === null) {
            return null;
        }

        $translated = [];
        $changed = 0;
        foreach ($paragraphs as $paragraph) {
            $trimmed = trim($paragraph);
            if (isset($translations[$trimmed]) && $translations[$trimmed] !== $trimmed) {
                $translated[] = str_replace($trimmed, $translations[$trimmed], $paragraph);
                $changed++;
                continue;
            }
            $translated[] = $paragraph;
        }

        // Nothing came back translated — report failure rather than hand back a
        // source-language body the caller would pair with a translated subject.
        if ($changed === 0) {
            return null;
        }

        return implode("\n\n", $translated);
    }
}
