<?php

if (!defined('ABSPATH')) {
    exit;
}

// Only do per-page work (which may hit the API or mint a nonce) when actually
// rendering the settings admin page. This file is required on every `init`
// (front-end, admin, and REST), so doing it unconditionally would churn the
// connect-state nonce and fetch site config on every request.
$universally_connect_url = '';
$universally_project_id  = '';
if (
    is_admin()
    && isset($_GET['page']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    && sanitize_key(wp_unslash($_GET['page'])) === UNIVERSALLY_SETTINGS_KEY // phpcs:ignore WordPress.Security.NonceVerification.Recommended
) {
    if (class_exists(\Universally\Onboarding::class)) {
        $universally_connect_url = (new \Universally\Onboarding())->buildConnectUrl();
    }
    // Project id lets the Languages table deep-link into the dashboard
    // ({app}/projects/{id}/languages). Empty when not connected.
    $universally_project_id = universally_get_site_id();
}

// "Dashboard" header link: deep-link straight to the connected project when we
// know its id, otherwise fall back to the app root (same URL the function
// resolves, honoring the UNIVERSALLY_APP_URL wp-config override).
$universally_dashboard_url = universally_get_app_url();
if ($universally_project_id !== '') {
    $universally_dashboard_url = rtrim($universally_dashboard_url, '/') . '/projects/' . $universally_project_id;
}

return [
    'id' => 'universally_settings',
    'title' => 'Universally',
    'logoPath' => '/assets/logo-full-dark.svg',
    'headerActions' => [
        [
            'icon' => 'dashicons-admin-site',
            'label' => __('Dashboard', 'universally-language-translation-multilingual-tool'),
            // Deep-links to the connected project when known, else the app root.
            'href' => $universally_dashboard_url,
        ],
        [
            'icon' => 'dashicons-book',
            'label' => __('Docs', 'universally-language-translation-multilingual-tool'),
            'href' => 'https://universally.com/docs/',
        ],
    ],
    'menu' => [
        'location' => 'toplevel',
        'icon' => 'dashicons-admin-generic',
        'iconPath' => '/assets/menu-icon.svg',
        // Mirror the panel's tabs as sidebar submenu items (General, Language
        // Switcher, Styling, Settings).
        'submenuTabs' => true,
    ],
    'schema' => [
        [
            'type' => 'tab',
            'id' => 'general_tab',
            'label' => __('General', 'universally-language-translation-multilingual-tool'),
        ],
        [
            'type' => 'section',
            'id' => 'api_section',
            'label' => __('API', 'universally-language-translation-multilingual-tool'),
            'showSave' => false,
        ],
        [
            'id' => 'api_key',
            'type' => 'api-key',
            'endpoint' => 'universally/v1/validate-api-key',
            'label' => __('Connection', 'universally-language-translation-multilingual-tool'),
            'placeholder' => __('64-character API key', 'universally-language-translation-multilingual-tool'),
            'validate' => 'regex:/^[a-fA-F0-9]{64}$/',
            'sanitize' => 'trim|text_field',
            'connect' => true,
            'connectUrl' => $universally_connect_url,
            'connectLabel' => __('Connect to Universally', 'universally-language-translation-multilingual-tool'),
            // Shown only in the disconnected state (the connected state is a
            // status block, so a static "connect…" description would be wrong).
            'connectDescription' => __('Connect your site to Universally to start translating. We’ll guide you through account setup, your plan, and languages — then bring you right back here.', 'universally-language-translation-multilingual-tool'),
            'connectedLabel' => __('Your site is connected to Universally', 'universally-language-translation-multilingual-tool'),
            'manualLabel' => __('Already have an API key? Enter it manually', 'universally-language-translation-multilingual-tool'),
            'disconnectLabel' => __('Disconnect', 'universally-language-translation-multilingual-tool'),
            'disconnectConfirmTitle' => __('Disconnect from Universally', 'universally-language-translation-multilingual-tool'),
            'disconnectConfirmLabel' => __('Disconnect this site from Universally? Translation will stop until you reconnect.', 'universally-language-translation-multilingual-tool'),
            'disconnectConfirmButton' => __('Yes, disconnect', 'universally-language-translation-multilingual-tool'),
            'disconnectCancelLabel' => __('Cancel', 'universally-language-translation-multilingual-tool'),
            'disconnectedLabel' => __('Universally disconnected', 'universally-language-translation-multilingual-tool'),
            'statusLabel' => __('API status', 'universally-language-translation-multilingual-tool'),
            'statusValue' => __('Operational', 'universally-language-translation-multilingual-tool'),
        ],
        [
            'type' => 'section',
            'id' => 'languages_list_section',
            'label' => __('Languages', 'universally-language-translation-multilingual-tool'),
            'showSave' => false,
        ],
        [
            'id' => 'languages',
            'type' => 'languages-table',
            'label' => '',
            'endpoint' => 'universally/v1/languages',
            // "Add Languages" link target — resolves the wp-config override.
            'appUrl' => universally_get_app_url(),
            // When known, deep-links to this project's language panel:
            // {appUrl}/projects/{projectId}/languages.
            'projectId' => $universally_project_id,
        ],
        [
            'type' => 'tab',
            'id' => 'language_switcher_tab',
            'label' => __('Language Switcher', 'universally-language-translation-multilingual-tool'),
        ],
        [
            'type' => 'section',
            'id' => 'language_switcher_section',
            'label' => __('Language Switcher', 'universally-language-translation-multilingual-tool'),
        ],
        [
            'id' => 'implementation',
            'type' => 'cards',
            'label' => __('Implementation', 'universally-language-translation-multilingual-tool'),
            'description' => __('Select how do you want to implement the language switcher on site.', 'universally-language-translation-multilingual-tool'),
            'sanitize' => 'key',
            'columns' => 2,
            'options' => [
                'auto' => [
                    'label' => __('Auto', 'universally-language-translation-multilingual-tool'),
                    'description' => __('Automatically insert in the page without any code needed', 'universally-language-translation-multilingual-tool'),
                ],
                'custom' => [
                    'label' => __('Custom', 'universally-language-translation-multilingual-tool'),
                    'description' => __('Use a shortcode to insert the language switcher in your theme', 'universally-language-translation-multilingual-tool'),
                ],
            ],
            'max' => 1,
            'default' => 'auto',
        ],
        [
            'id' => 'position',
            'type' => 'select',
            'label' => __('Position', 'universally-language-translation-multilingual-tool'),
            'description' => __('Choose where to automatically insert the language switcher. This will only be used if you select the **Auto** {implementation} option.', 'universally-language-translation-multilingual-tool'),
            'options' => [
                'bottom_right' => __('Bottom Right', 'universally-language-translation-multilingual-tool'),
                'bottom_left' => __('Bottom Left', 'universally-language-translation-multilingual-tool'),
                'top_right' => __('Top Right', 'universally-language-translation-multilingual-tool'),
                'top_left' => __('Top Left', 'universally-language-translation-multilingual-tool'),
            ],
            'default' => 'bottom_right',
            'sanitize' => 'key',
            'conditions' => [
                'implementation = auto',
            ],
        ],
        [
            'id' => 'language_switcher_shortcode',
            'type' => 'copyable',
            'label' => __('Shortcode', 'universally-language-translation-multilingual-tool'),
            'description' => __('Use this shortcode to insert the language switcher in a custom place.', 'universally-language-translation-multilingual-tool'),
            'content' => '[universally_switcher]',
            'buttonText' => __('Copy Code', 'universally-language-translation-multilingual-tool'),
            'separator' => false,
            'conditions' => [
                'implementation = custom',
            ],
        ],
        [
            'id' => 'language_switcher_php_code',
            'type' => 'copyable',
            'label' => __('PHP Code', 'universally-language-translation-multilingual-tool'),
            'description' => __('Use this PHP code to insert the language switcher in a custom place.', 'universally-language-translation-multilingual-tool'),
            'content' => "if (function_exists('universally_switcher')) {\n   universally_switcher();\n}\n",
            'buttonText' => __('Copy Code', 'universally-language-translation-multilingual-tool'),
            'separator' => false,
            'conditions' => [
                'implementation = custom',
            ],
        ],
        [
            'id' => 'show_language_names',
            'type' => 'toggle',
            'label' => __('Language Names', 'universally-language-translation-multilingual-tool'),
            'inlineLabel' => __('Display the language names', 'universally-language-translation-multilingual-tool'),
            'default' => true,
            'sanitize' => 'bool',
            'separator' => false,
        ],
        [
            'id' => 'show_country_flags',
            'type' => 'toggle',
            'label' => __('Country Flags', 'universally-language-translation-multilingual-tool'),
            'inlineLabel' => __('Display the country flags', 'universally-language-translation-multilingual-tool'),
            'default' => true,
            'sanitize' => 'bool',
            'separator' => false,
        ],
        [
            'id' => 'flag_style',
            'type' => 'select',
            'label' => __('Flag Style', 'universally-language-translation-multilingual-tool'),
            'description' => __('Choose the style of the country flags.', 'universally-language-translation-multilingual-tool'),
            'options' => [
                'rounded' => __('Rounded', 'universally-language-translation-multilingual-tool'),
                'square' => __('Square', 'universally-language-translation-multilingual-tool'),
            ],
            'conditions' => [
                'show_country_flags = true',
            ],
            'default' => 'rounded',
            'sanitize' => 'key',
        ],
        [
            'type' => 'tab',
            'id' => 'styling_tab',
            'label' => __('Styling', 'universally-language-translation-multilingual-tool'),
        ],
        [
            'type' => 'section',
            'id' => 'trigger_styling_section',
            'label' => __('Trigger Styling', 'universally-language-translation-multilingual-tool'),
        ],
        [
            'id' => 'trigger_bg',
            'type' => 'color',
            'label' => __('Background', 'universally-language-translation-multilingual-tool'),
            'sanitize' => 'trim|text_field',
            'separator' => false,
            'palette' => 'background',
        ],
        [
            'id' => 'trigger_text',
            'type' => 'color',
            'label' => __('Text Color', 'universally-language-translation-multilingual-tool'),
            'sanitize' => 'trim|text_field',
            'separator' => false,
            'palette' => 'text',
        ],
        [
            'id' => 'trigger_border',
            'type' => 'color',
            'label' => __('Border Color', 'universally-language-translation-multilingual-tool'),
            'sanitize' => 'trim|text_field',
            'separator' => false,
            'palette' => 'border',
        ],
        [
            'id' => 'trigger_border_hover',
            'type' => 'color',
            'label' => __('Border Hover', 'universally-language-translation-multilingual-tool'),
            'sanitize' => 'trim|text_field',
            'separator' => false,
            'palette' => 'border',
        ],
        [
            'id' => 'trigger_radius',
            'type' => 'range',
            'label' => __('Border Radius', 'universally-language-translation-multilingual-tool'),
            'min' => 0,
            'max' => 24,
            'step' => 1,
            'suffix' => 'px',
            'default' => 6,
            'sanitize' => 'trim|text_field',
        ],
        [
            'type' => 'section',
            'id' => 'dropdown_styling_section',
            'label' => __('Dropdown Styling', 'universally-language-translation-multilingual-tool'),
        ],
        [
            'id' => 'dropdown_bg',
            'type' => 'color',
            'label' => __('Background', 'universally-language-translation-multilingual-tool'),
            'sanitize' => 'trim|text_field',
            'separator' => false,
            'palette' => 'background',
        ],
        [
            'id' => 'dropdown_text',
            'type' => 'color',
            'label' => __('Text Color', 'universally-language-translation-multilingual-tool'),
            'sanitize' => 'trim|text_field',
            'separator' => false,
            'palette' => 'text',
        ],
        [
            'id' => 'dropdown_border',
            'type' => 'color',
            'label' => __('Border Color', 'universally-language-translation-multilingual-tool'),
            'sanitize' => 'trim|text_field',
            'separator' => false,
            'palette' => 'border',
        ],
        [
            'id' => 'dropdown_hover_bg',
            'type' => 'color',
            'label' => __('Item Hover Background', 'universally-language-translation-multilingual-tool'),
            'sanitize' => 'trim|text_field',
            'separator' => false,
            'palette' => 'background',
        ],
        [
            'id' => 'dropdown_radius',
            'type' => 'range',
            'label' => __('Border Radius', 'universally-language-translation-multilingual-tool'),
            'min' => 0,
            'max' => 24,
            'step' => 1,
            'suffix' => 'px',
            'default' => 6,
            'sanitize' => 'trim|text_field',
        ],
        [
            'type' => 'tab',
            'id' => 'settings_tab',
            'label' => __('Preferences', 'universally-language-translation-multilingual-tool'),
        ],
        [
            'type' => 'section',
            'id' => 'browser_translation_section',
            'label' => __('Browser Translation', 'universally-language-translation-multilingual-tool'),
        ],
        [
            'id' => 'prevent_browser_translation',
            'type' => 'toggle',
            'label' => __('Prevent browser auto-translation', 'universally-language-translation-multilingual-tool'),
            'inlineLabel' => __('Stop browsers from offering to auto-translate your pages', 'universally-language-translation-multilingual-tool'),
            'description' => __('Adds a notranslate meta tag and the translate="no" attribute so Chrome, Edge, and other browsers don’t offer to auto-translate your pages — visitors use your Universally translations instead.', 'universally-language-translation-multilingual-tool'),
            'default' => true,
            'sanitize' => 'bool',
        ],
        [
            'type' => 'section',
            'id' => 'privacy_section',
            'label' => __('Privacy', 'universally-language-translation-multilingual-tool'),
        ],
        [
            'id' => 'usage_tracking',
            'type' => 'toggle',
            'label' => __('Anonymous Usage Data', 'universally-language-translation-multilingual-tool'),
            'inlineLabel' => __('Share anonymous usage data to help make Universally better for everyone', 'universally-language-translation-multilingual-tool'),
            'description' => __('You can opt out at any time. [Learn more about anonymous usage tracking.](https://universally.com/docs/usage-tracking-in-wordpress/)', 'universally-language-translation-multilingual-tool'),
            'default' => true,
            'sanitize' => 'bool',
        ],
    ],
];
