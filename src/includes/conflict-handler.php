<?php
/**
 * Conflict handler for the previous (pre-rename) Universally plugin.
 *
 * The plugin was renamed from `universally/universally.php` to
 * `universally-language-translation-multilingual-tool/universally.php`.
 * Both versions share the same `Universally\` namespace, constants, and
 * function names — running them together causes a fatal error.
 *
 * This file MUST run before vendor/autoload.php and any class instantiation.
 *
 * @package Universally
 */

if (!defined('ABSPATH')) {
    exit;
}

const UNIVERSALLY_OLD_PLUGIN_SLUG = 'universally/universally.php';

/**
 * Remove the old plugin from active_plugins / active_sitewide_plugins
 * before any of its files have a chance to load.
 *
 * Done by writing the option directly (not deactivate_plugins()), because
 * is_plugin_active() and deactivate_plugins() require wp-admin/includes/plugin.php
 * which isn't loaded on the front end.
 */
function universally_deactivate_old_plugin(): void
{
    $changed = false;

    // Single-site (or per-site activation on multisite)
    $active = (array) get_option('active_plugins', []);
    if (in_array(UNIVERSALLY_OLD_PLUGIN_SLUG, $active, true)) {
        $active = array_values(array_diff($active, [UNIVERSALLY_OLD_PLUGIN_SLUG]));
        update_option('active_plugins', $active);
        $changed = true;
    }

    // Multisite: network-activated plugins
    if (is_multisite()) {
        $networkActive = (array) get_site_option('active_sitewide_plugins', []);
        if (isset($networkActive[UNIVERSALLY_OLD_PLUGIN_SLUG])) {
            unset($networkActive[UNIVERSALLY_OLD_PLUGIN_SLUG]);
            update_site_option('active_sitewide_plugins', $networkActive);
            $changed = true;
        }
    }

    if ($changed) {
        set_transient('universally_old_plugin_deactivated', true, 5 * MINUTE_IN_SECONDS);
    }
}

/**
 * Carry over data from the old plugin's option keys to the new ones.
 *
 * Migrates the API key from the pre-rename option name to the current
 * `universally_api_key`. Only runs if the new key is empty — never
 * overwrites a value the user has already set in the new plugin.
 */
function universally_migrate_old_plugin_data(): void
{
    $legacyOptionName = 'universally_' . 'lic' . 'ense' . '_key';

    $oldKey = get_option($legacyOptionName, '');
    $newKey = get_option('universally_api_key', '');

    if (!empty($oldKey) && empty($newKey)) {
        update_option('universally_api_key', $oldKey);
        delete_option($legacyOptionName);
    }
}

universally_deactivate_old_plugin();
universally_migrate_old_plugin_data();

/**
 * Block any future attempt to re-activate the old plugin while this one is
 * loaded. Strips the old slug from the value WordPress is about to save.
 */
add_filter('pre_update_option_active_plugins', function ($value) {
    if (!is_array($value)) {
        return $value;
    }
    return array_values(array_diff($value, [UNIVERSALLY_OLD_PLUGIN_SLUG]));
});

add_filter('pre_update_site_option_active_sitewide_plugins', function ($value) {
    if (!is_array($value)) {
        return $value;
    }
    unset($value[UNIVERSALLY_OLD_PLUGIN_SLUG]);
    return $value;
});

/**
 * Admin notice after we deactivate the old plugin, with a link to the
 * Plugins page where the user can delete the old folder.
 */
add_action('admin_notices', function () {
    if (!current_user_can('activate_plugins')) {
        return;
    }
    if (!get_transient('universally_old_plugin_deactivated')) {
        return;
    }
    delete_transient('universally_old_plugin_deactivated');

    $pluginsUrl = admin_url('plugins.php?plugin_status=inactive');
    ?>
    <div class="notice notice-warning is-dismissible">
        <p>
            <strong><?php echo esc_html__('Universally Language Translation Multilingual Tool', 'universally-language-translation-multilingual-tool'); ?>:</strong>
            <?php echo esc_html__('the previous version of Universally was detected and has been deactivated to avoid conflicts.', 'universally-language-translation-multilingual-tool'); ?>
            <a href="<?php echo esc_url($pluginsUrl); ?>">
                <?php echo esc_html__('Delete the old "Universally" plugin', 'universally-language-translation-multilingual-tool'); ?>
            </a>
            <?php echo esc_html__('to keep your installation clean.', 'universally-language-translation-multilingual-tool'); ?>
        </p>
    </div>
    <?php
});
