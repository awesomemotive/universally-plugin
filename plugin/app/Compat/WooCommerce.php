<?php
/**
 * WooCommerce compatibility.
 *
 * Ensures same-origin form actions / outbound URLs WooCommerce emits carry
 * the language prefix, so the resulting POST or navigation stays in the
 * visitor's language.
 *
 * @package Universally
 */

namespace Universally\Compat;

if (!defined('ABSPATH')) {
    exit;
}

class WooCommerce implements Integration
{
    public function isActive(): bool
    {
        return class_exists('WooCommerce');
    }

    public function register(string $langCode): void
    {
        // Form action for the single-product add-to-cart form (the case where
        // submitting otherwise drops the visitor to the unprefixed product URL).
        add_filter('woocommerce_add_to_cart_form_action', function (string $url) use ($langCode): string {
            return universally_prefix_url_with_language($url, $langCode);
        });
    }
}
