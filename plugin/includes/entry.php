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

/**
 * Bust the site-config / languages transients whenever the user visits the
 * Universally settings page in wp-admin. They've likely been editing settings
 * in the dashboard and now want the WP side to reflect the new state without
 * waiting out the 15-minute transient TTL.
 *
 * Both hook names are registered to cover toplevel and submenu placements.
 */
function universally_bust_site_config_transients(): void
{
    delete_transient('universally_site_config');
    delete_transient('universally_all_languages');
}
add_action('load-toplevel_page_' . UNIVERSALLY_SETTINGS_KEY, 'universally_bust_site_config_transients');
add_action('load-settings_page_' . UNIVERSALLY_SETTINGS_KEY, 'universally_bust_site_config_transients');

register_activation_hook(UNIVERSALLY_PLUGIN_FILE, function (): void {
    flush_rewrite_rules();
    Log::info('Universally plugin activated');
});

register_deactivation_hook(UNIVERSALLY_PLUGIN_FILE, function (): void {
    flush_rewrite_rules();
    Log::info('Universally plugin deactivated');
});
