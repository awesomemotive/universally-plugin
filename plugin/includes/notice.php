<?php
/**
* Notice component for displaying admin notices in the WordPress dashboard
*
* Initializes core components and sets up hooks.
*
* @package Universally
*/

if (!defined('ABSPATH')) {
exit;
}

add_action('admin_notices', 'universally_activation_notice');
add_action('admin_enqueue_scripts', 'universally_activation_notice_enqueue');
add_action('wp_ajax_universally_dismiss_notice', 'universally_activation_notice_handler');

/**
 * Handle notice dismissal via AJAX
 *
 * @return void
 */
function universally_activation_notice_handler(): void
{
    // Check permission
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
        return;
    }

    // Verify nonce
    check_ajax_referer('universally_dismiss_notice', 'nonce');

    // Set transient for 1 day (86400 seconds)
    set_transient('universally_notice_dismissed', true, DAY_IN_SECONDS);

    wp_send_json_success();
}

/**
 * Determine whether the activation notice should be shown.
 *
 * @return bool
 */
function universally_activation_notice_should_show(): bool
{
    if (!current_user_can('manage_options')) {
        return false;
    }

    $screen = get_current_screen();
    if ($screen && $screen->id === 'settings_page_universally') {
        return false;
    }

    // Don't show the "not connected" notice on the connect/callback page —
    // the user is in the middle of connecting there.
    if ($screen && strpos((string) $screen->id, 'universally-connect') !== false) {
        return false;
    }

    if (get_transient('universally_notice_dismissed')) {
        return false;
    }

    $key = universally_get_api_key();
    if (!empty($key)) {
        return false;
    }

    return true;
}

/**
 * Enqueue the dismissal script for the activation notice.
 *
 * @return void
 */
function universally_activation_notice_enqueue(): void
{
    if (!universally_activation_notice_should_show()) {
        return;
    }

    wp_register_script('universally-notice-dismiss', '', ['jquery'], UNIVERSALLY_VERSION, true);
    wp_enqueue_script('universally-notice-dismiss');

    $nonce = wp_create_nonce('universally_dismiss_notice');
    $script = sprintf(
        'jQuery(function($){'
            . '$(document).on("click", \'[data-dismissible="universally-notice"] .notice-dismiss\', function(){'
                . '$.post(ajaxurl, { action: "universally_dismiss_notice", nonce: %s });'
            . '});'
        . '});',
        wp_json_encode($nonce)
    );

    wp_add_inline_script('universally-notice-dismiss', $script);
}

/**
 * Show admin notice if plugin is not connected
 *
 * @return void
 */
function universally_activation_notice(): void
{
    if (!universally_activation_notice_should_show()) {
        return;
    }

    $settingsUrl = admin_url('options-general.php?page=' . UNIVERSALLY_SETTINGS_KEY);
    ?>
    <div class="notice notice-warning is-dismissible" data-dismissible="universally-notice">
        <p>
            <strong><?php echo esc_html__('Universally', 'universally-language-translation-multilingual-tool'); ?></strong>
            <?php echo esc_html__('is installed but not connected.', 'universally-language-translation-multilingual-tool'); ?>
            <a href="<?php echo esc_url($settingsUrl); ?>">
                <?php echo esc_html__('Connect your site now', 'universally-language-translation-multilingual-tool'); ?>
            </a>
            <?php echo esc_html__('to start translating your content.', 'universally-language-translation-multilingual-tool'); ?>
        </p>
    </div>
    <?php
}
