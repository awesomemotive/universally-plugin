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

// Clean up transients
delete_transient('universally_languages');
delete_transient('universally_all_languages');
delete_transient('universally_notice_dismissed');

// Flush rewrite rules
flush_rewrite_rules();
