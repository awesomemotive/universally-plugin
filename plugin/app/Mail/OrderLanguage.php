<?php
/**
 * Captures the customer's browsing language on the order at checkout time
 * and reads it back when transactional emails are sent later (from admin,
 * cron, or webhook contexts where no frontend language detection runs).
 *
 * @package Universally
 */

namespace Universally\Mail;

if (!defined('ABSPATH')) {
    exit;
}

class OrderLanguage
{
    public const META_KEY = '_universally_customer_language';

    public function __construct()
    {
        // Classic checkout: order not yet saved, meta persists with it.
        add_action('woocommerce_checkout_create_order', [$this, 'captureClassic']);
        // Blocks (Store API) checkout: order already exists, needs an explicit save.
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'captureStoreApi']);
    }

    /**
     * @param \WC_Order $order
     */
    public function captureClassic($order): void
    {
        $locale = $this->currentLocale();

        if ($locale !== null && $order instanceof \WC_Order) {
            $order->update_meta_data(self::META_KEY, $locale);
        }
    }

    /**
     * @param \WC_Order $order
     */
    public function captureStoreApi($order): void
    {
        $locale = $this->currentLocale();

        if ($locale !== null && $order instanceof \WC_Order) {
            $order->update_meta_data(self::META_KEY, $locale);
            $order->save();
        }
    }

    /**
     * Locale the visitor is currently browsing in.
     *
     * Primary: UNIVERSALLY_CURRENT_LOCALE (set by UnifiedBuffer via URL
     * prefix or same-origin referer). Fallback: the 30-day universally_lang
     * cookie (URL prefix), resolved to a locale variant — covers Store API
     * checkouts where the constant may not be defined.
     */
    private function currentLocale(): ?string
    {
        $locale = universally_get_current_locale();

        if ($locale !== null) {
            return $locale;
        }

        if (empty($_COOKIE['universally_lang'])) {
            return null;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- guarded by empty() above, sanitized below.
        $urlCode = sanitize_key(wp_unslash((string) $_COOKIE['universally_lang']));

        foreach (universally_get_all_languages() as $language) {
            if (
                isset($language['urlPrefix'], $language['variant'])
                && $language['urlPrefix'] === $urlCode
                && empty($language['isSource'])
                && empty($language['isDisabled'])
            ) {
                return $language['variant'];
            }
        }

        return null;
    }

    /**
     * Locale stored on the order, validated against the site's current
     * language list (null if the language was disabled/removed since).
     */
    public static function getOrderLocale(\WC_Order $order): ?string
    {
        $locale = (string) $order->get_meta(self::META_KEY);

        if ($locale === '') {
            return null;
        }

        foreach (universally_get_all_languages() as $language) {
            if (
                isset($language['variant'])
                && $language['variant'] === $locale
                && empty($language['isSource'])
                && empty($language['isDisabled'])
            ) {
                return $locale;
            }
        }

        return null;
    }
}
