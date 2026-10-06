<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin settings
delete_option('universally_settings');
delete_option('universally_api_key');
delete_option('universally_' . 'lic' . 'ense' . '_key'); // Legacy option name from pre-rename plugin
delete_option('universally_onboard');
delete_option('universally_migrations_completed');

// Per-user UI state
delete_metadata('user', 0, 'universally_general_hero_dismissed', '', true);

// Clean up transients
delete_transient('universally_languages');
delete_transient('universally_all_languages');
delete_transient('universally_notice_dismissed');
delete_transient('universally_key_status');

// Flush rewrite rules
flush_rewrite_rules();
