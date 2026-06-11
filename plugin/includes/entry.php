<?php

/**
 * Entry point for Universally plugin
 *
 * Initializes core components and sets up hooks.
 *
 * @package Universally
 */

use Universally\Log;
use UniversallyPanel\Panel\Panel;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add Settings link to the row actions on the Plugins page.
 * Uses UNIVERSALLY_PLUGIN_FILE (the main plugin file) — `__FILE__` here
 * is includes/entry.php, which produces a hook name WordPress never fires.
 */
add_filter('plugin_action_links_' . plugin_basename(UNIVERSALLY_PLUGIN_FILE), function (array $links): array {
    $url = admin_url('admin.php?page=' . UNIVERSALLY_SETTINGS_KEY);
    array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'universally-language-translation-multilingual-tool') . '</a>');
    return $links;
});

/**
 * Register settings panel
 */
add_action('init', function (): void {
    $config = require UNIVERSALLY_PLUGIN_DIR . 'includes/settings.php';
    Panel::fromArray($config, UNIVERSALLY_PLUGIN_DIR . 'includes')->register();
}, 0);

add_action('wp_head', 'universally_hreflang_tags', 1);
add_action('wp_head', 'universally_notranslate_meta', 1);
add_filter('language_attributes', 'universally_html_translate_attr');

register_activation_hook(UNIVERSALLY_PLUGIN_FILE, function (): void {
    flush_rewrite_rules();
    Log::info('Universally plugin activated');
});

register_deactivation_hook(UNIVERSALLY_PLUGIN_FILE, function (): void {
    flush_rewrite_rules();
    Log::info('Universally plugin deactivated');
});
