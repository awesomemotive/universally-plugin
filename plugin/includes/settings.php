<?php

if (!defined('ABSPATH')) {
    exit;
}

// Only do per-page work (which may hit the API) when actually rendering the
// settings admin page. This file is required on every `init` (front-end, admin,
// and REST), so doing it unconditionally would fetch site config on every request.
$universally_connect_url = '';
$universally_project_id  = '';
// Stored key status. Off the settings page assume a stored key works (no API
// call), so REST saves and the front end always see the connected schema.
$universally_key_status = \Universally\KeyStatus::assumed();
if (
    is_admin()
    && isset($_GET['page']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    && sanitize_key(wp_unslash($_GET['page'])) === UNIVERSALLY_SETTINGS_KEY // phpcs:ignore WordPress.Security.NonceVerification.Recommended
) {
    if (class_exists(\Universally\Onboarding::class)) {
        // Start at the plugin's own Welcome step (usage-consent checkbox, then
        // "Let's Get Started" into the hosted flow), the same entry the
        // post-activation redirect uses. Linking straight to the hosted
        // /connect/account skipped that step from this tab.
        $universally_connect_url = admin_url('admin.php?page=' . \Universally\Onboarding::CALLBACK_SLUG);
    }
    // Project id lets the Languages table deep-link into the dashboard
    // ({app}/projects/{id}/languages). Empty when not connected.
    $universally_project_id = universally_get_site_id();
    // Live (cached for 5 minutes) check, so a rejected key or an API outage
    // gets its own General tab instead of the normal connected one.
    $universally_key_status = \Universally\KeyStatus::get();
}

// "Dashboard" header link: deep-link straight to the connected project when we
// know its id, otherwise fall back to the app root (same URL the function
// resolves, honoring the UNIVERSALLY_APP_URL wp-config override).
$universally_dashboard_url = universally_get_app_url();
if ($universally_project_id !== '') {
    $universally_dashboard_url = rtrim($universally_dashboard_url, '/') . '/projects/' . $universally_project_id;
}

// Panel-wide notices, rendered above the tabs bar on every tab. Universally
// serves translated pages under a language prefix (/es/…), which the Plain
// permalink structure cannot express — warn instead of failing silently.
$universally_panel_notices = [];
if (get_option('permalink_structure') === '') {
    $universally_panel_notices[] = [
        'id' => 'plain_permalinks',
        'type' => 'warning',
        'title' => __('Pretty permalinks are required', 'universally-language-translation-multilingual-tool'),
        'message' => __('Your site uses Plain permalinks (?p=123). Universally serves translated pages under a language prefix such as /es/, which needs pretty permalinks. Translations will not work until you switch to any other permalink structure.', 'universally-language-translation-multilingual-tool'),
        'action' => [
            'label' => __('Change permalink settings', 'universally-language-translation-multilingual-tool'),
            'href' => admin_url('options-permalink.php'),
        ],
    ];
}

/**
 * Filter the notices shown at the top of the settings panel
 *
 * Each notice is an array with `id`, `type` (warning|error|info), `message`,
 * and optionally `title` and `action` (`label` + `href`).
 *
 * @param array $universally_panel_notices
 */
$universally_panel_notices = apply_filters('universally_panel_notices', $universally_panel_notices);

// General tab. Connected sites get the connection status + languages table.
// Sites that aren't connected yet get a single landing screen (hero, language
// suggestions, steps, CTA) that funnels into the hosted connect flow; manual
// key entry is still reachable from inside it. "Connected" means an API key is
// stored — the same test Onboarding and the activation notice use.
//
// A stored key the API rejects gets the landing hero in "reconnect" mode
// instead; one we couldn't check (API down) keeps the connected tab with a
// banner on top. See \Universally\KeyStatus.
$universally_is_connected = universally_get_api_key() !== '';
$universally_status_name  = $universally_key_status['status'];

// Shared by the landing (all modes) and the status banner.
$universally_landing_base = [
    'label' => '',
    'size' => 'full',
    // Nothing to save — the landing only links out and calls its own endpoints.
    'independent' => true,
    'connectUrl' => $universally_connect_url,
    'heroVideoUrl' => 'https://cdn.universally.com/videos/concept-3d-checklist.mp4',
    // api-key endpoint: manual key entry, "Check again" (?fresh=1), key removal.
    'apiKeyEndpoint' => 'universally/v1/validate-api-key',
    'apiKeyPlaceholder' => __('64-character API key', 'universally-language-translation-multilingual-tool'),
];

// Strings for "Check again" (reconnect + banner modes).
$universally_check_strings = [
    'checkAgain' => __('Check again', 'universally-language-translation-multilingual-tool'),
    'checking' => __('Checking…', 'universally-language-translation-multilingual-tool'),
    'checkedJustNow' => __('checked just now', 'universally-language-translation-multilingual-tool'),
    'checkFailed' => __('Couldn’t check the connection right now. Please try again.', 'universally-language-translation-multilingual-tool'),
    'recovered' => __('Your API key works again. Reloading…', 'universally-language-translation-multilingual-tool'),
];

if ($universally_is_connected && $universally_status_name === \Universally\KeyStatus::REJECTED) {
    $universally_general_items = [
        [
            'type' => 'section',
            'id' => 'general_reconnect_section',
            'label' => __('Connection lost', 'universally-language-translation-multilingual-tool'),
            'showSave' => false,
            'bare' => true,
        ],
        $universally_landing_base + [
            'id' => 'general_landing',
            'type' => 'general-landing',
            'mode' => 'reconnect',
            'maskedKey' => \Universally\KeyStatus::shortMask(universally_get_api_key()),
            'statusMessage' => $universally_key_status['message'],
            'checkedLabel' => \Universally\KeyStatus::checkedLabel($universally_key_status['checked_at']),
            // A key set via the UNIVERSALLY_API_KEY constant can't be replaced
            // or removed from here.
            'keyLocked' => defined('UNIVERSALLY_API_KEY'),
            'strings' => $universally_check_strings + [
                'alertPill' => __('Connection lost', 'universally-language-translation-multilingual-tool'),
                'reconnectTitle' => __('Reconnect your site to keep translating', 'universally-language-translation-multilingual-tool'),
                'reconnectText' => __('Universally no longer accepts the API key saved on this site, so translation has stopped. Reconnecting takes about a minute and brings you right back here.', 'universally-language-translation-multilingual-tool'),
                'reconnect' => __('Reconnect to Universally', 'universally-language-translation-multilingual-tool'),
                'newKeyLabel' => __('Have a new API key? Enter it', 'universally-language-translation-multilingual-tool'),
                'removeKey' => __('Remove saved key', 'universally-language-translation-multilingual-tool'),
                'removeConfirm' => __('Yes, remove it', 'universally-language-translation-multilingual-tool'),
                'removeCancel' => __('Cancel', 'universally-language-translation-multilingual-tool'),
                'removing' => __('Removing…', 'universally-language-translation-multilingual-tool'),
                'removeFailed' => __('Couldn’t remove the key. Please try again.', 'universally-language-translation-multilingual-tool'),
                'languagesTitle' => __('Languages', 'universally-language-translation-multilingual-tool'),
                'languagesLocked' => __('Your languages show here again once the site is reconnected.', 'universally-language-translation-multilingual-tool'),
            ],
        ],
    ];
} elseif ($universally_is_connected) {
    $universally_general_items = [];

    if ($universally_status_name === \Universally\KeyStatus::UNKNOWN) {
        $universally_general_items[] = [
            'type' => 'section',
            'id' => 'general_status_section',
            'label' => __('Connection check', 'universally-language-translation-multilingual-tool'),
            'showSave' => false,
            'bare' => true,
        ];
        $universally_general_items[] = $universally_landing_base + [
            'id' => 'general_status_banner',
            'type' => 'general-landing',
            'mode' => 'banner',
            'strings' => $universally_check_strings + [
                'bannerTitle' => __('Couldn’t reach Universally to check your connection', 'universally-language-translation-multilingual-tool'),
                'bannerText' => \Universally\KeyStatus::lastCheckedLabel($universally_key_status['checked_at']),
                'bannerTextJustNow' => __('Your site keeps its current setup. Last checked just now.', 'universally-language-translation-multilingual-tool'),
                'bannerRecovered' => __('Universally is reachable again. Your API key works.', 'universally-language-translation-multilingual-tool'),
            ],
        ];
    }

    $universally_general_items = array_merge($universally_general_items, [
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
            // Initial connection state. The api-key field's Connect/Disconnect
            // keeps the table in sync afterwards without a reload.
            'connected' => !empty(universally_get_api_key()),
            // Count strings take a number (%d); both plural forms are passed
            // and picked client-side, since the count is only known there.
            'strings' => [
                'countNone' => __('None yet', 'universally-language-translation-multilingual-tool'),
                /* translators: %d: number of languages just added. */
                'addedOne' => _n('%d added', '%d added', 1, 'universally-language-translation-multilingual-tool'),
                /* translators: %d: number of languages just added. */
                'addedMany' => _n('%d added', '%d added', 2, 'universally-language-translation-multilingual-tool'),
                /* translators: %d: number of live languages, the source language included. */
                'liveOne' => _n('%d live', '%d live', 1, 'universally-language-translation-multilingual-tool'),
                /* translators: %d: number of live languages, the source language included. */
                'liveMany' => _n('%d live', '%d live', 2, 'universally-language-translation-multilingual-tool'),
                'refresh' => __('Refresh languages', 'universally-language-translation-multilingual-tool'),
                'manage' => __('Manage in dashboard', 'universally-language-translation-multilingual-tool'),
                'opensInNewTab' => __('(opens in a new tab)', 'universally-language-translation-multilingual-tool'),
                'loading' => __('Loading languages…', 'universally-language-translation-multilingual-tool'),
                /* translators: %s: the site's source language name, e.g. English. */
                'sourceIs' => __('Your site is in %s', 'universally-language-translation-multilingual-tool'),
                'emptyTitle' => __('Pick the first language to translate into', 'universally-language-translation-multilingual-tool'),
                'emptyText' => __('Universally translates your whole site into it and publishes it under its own URL, like /es/. Most sites start with one or two.', 'universally-language-translation-multilingual-tool'),
                'browseAll' => __('Browse all 110+ languages', 'universally-language-translation-multilingual-tool'),
                /* translators: %s: number of millions, e.g. 485. */
                'speakersMillion' => __('%s million speakers', 'universally-language-translation-multilingual-tool'),
                /* translators: %s: number of billions, e.g. 1.2. */
                'speakersBillion' => __('%s billion speakers', 'universally-language-translation-multilingual-tool'),
                'add' => __('Add', 'universally-language-translation-multilingual-tool'),
                'adding' => __('Adding…', 'universally-language-translation-multilingual-tool'),
                'added' => __('Added', 'universally-language-translation-multilingual-tool'),
                /* translators: %s: language name, e.g. Spanish. */
                'addLanguage' => __('Add %s', 'universally-language-translation-multilingual-tool'),
                'addFailed' => __('Could not add the language. Please try again.', 'universally-language-translation-multilingual-tool'),
                'tagHighPurchasingPower' => __('High purchasing power', 'universally-language-translation-multilingual-tool'),
                'tagFastGrowingMarket' => __('Fast-growing market', 'universally-language-translation-multilingual-tool'),
                'colLanguage' => __('Language', 'universally-language-translation-multilingual-tool'),
                'colUrl' => __('URL', 'universally-language-translation-multilingual-tool'),
                'colStatus' => __('Status', 'universally-language-translation-multilingual-tool'),
                'source' => __('Source', 'universally-language-translation-multilingual-tool'),
                'statusLive' => __('Live', 'universally-language-translation-multilingual-tool'),
                'statusDisabled' => __('Disabled', 'universally-language-translation-multilingual-tool'),
                'copyUrl' => __('Copy URL', 'universally-language-translation-multilingual-tool'),
                'copied' => __('Copied!', 'universally-language-translation-multilingual-tool'),
                'suggestedTitle' => __('Suggested next', 'universally-language-translation-multilingual-tool'),
                'suggestedText' => __('Popular with sites like yours', 'universally-language-translation-multilingual-tool'),
                'allLanguages' => __('All languages', 'universally-language-translation-multilingual-tool'),
                'upgradeLabel' => __('Upgrade to add more languages', 'universally-language-translation-multilingual-tool'),
                'upgradeTitle' => __('Upgrade to add more languages and reach a wider audience', 'universally-language-translation-multilingual-tool'),
                'upgradeText' => __('You’ve reached your plan’s language limit. Upgrade to keep translating into new markets and grow your global reach.', 'universally-language-translation-multilingual-tool'),
                'upgradeCta' => __('Upgrade plan & unlock more languages', 'universally-language-translation-multilingual-tool'),
                'upgradeBonus' => __('New languages go live automatically — translation starts the moment you upgrade.', 'universally-language-translation-multilingual-tool'),
                'maybeLater' => __('Maybe later', 'universally-language-translation-multilingual-tool'),
                'close' => __('Close', 'universally-language-translation-multilingual-tool'),
            ],
            // Display names for the one-click suggestions, keyed by the
            // backend variant code the component sends when adding.
            'languageNames' => [
                'es' => __('Spanish', 'universally-language-translation-multilingual-tool'),
                'zh-hans' => __('Chinese (Simplified)', 'universally-language-translation-multilingual-tool'),
                'ar' => __('Arabic', 'universally-language-translation-multilingual-tool'),
                'pt-br' => __('Portuguese (Brazil)', 'universally-language-translation-multilingual-tool'),
                'ja' => __('Japanese', 'universally-language-translation-multilingual-tool'),
                'de' => __('German', 'universally-language-translation-multilingual-tool'),
                'fr' => __('French', 'universally-language-translation-multilingual-tool'),
                'it' => __('Italian', 'universally-language-translation-multilingual-tool'),
                'nl' => __('Dutch', 'universally-language-translation-multilingual-tool'),
            ],
        ],
    ]);
} else {
    // Set by partner installers (e.g. AIOSEO's setup wizard); see
    // Onboarding::INSTALLED_BY_OPTION.
    $universally_installed_by = get_option('universally_installed_by', '');
    $universally_is_aioseo    = is_string($universally_installed_by) && strpos(strtolower($universally_installed_by), 'aioseo') === 0;

    // Per-user: dismissing the hero only hides it for the admin who closed it.
    $universally_hero_dismissed = (bool) get_user_meta(get_current_user_id(), \Universally\RestApi::HERO_DISMISSED_META, true);

    $universally_general_items = [
        [
            'type' => 'section',
            'id' => 'general_landing_section',
            'label' => __('Get started', 'universally-language-translation-multilingual-tool'),
            'showSave' => false,
            // Render the landing cards directly, without the section card chrome.
            'bare' => true,
        ],
        $universally_landing_base + [
            'id' => 'general_landing',
            'type' => 'general-landing',
            'docsUrl' => 'https://universally.com/docs/install-on-wordpress/',
            'assetsUrl' => esc_url_raw(UNIVERSALLY_PLUGIN_URI . 'assets/general/'),
            'isAioseo' => $universally_is_aioseo,
            'heroDismissed' => $universally_hero_dismissed,
            'dismissEndpoint' => 'universally/v1/dismiss-general-hero',
            'strings' => [
                'heroEyebrow' => __('Installed with All in One SEO', 'universally-language-translation-multilingual-tool'),
                'heroTitleAioseo' => __('Take your SEO global with Universally', 'universally-language-translation-multilingual-tool'),
                'heroTitle' => __('Take your site global with Universally', 'universally-language-translation-multilingual-tool'),
                'heroIntroAioseo' => __('You enabled multilingual SEO during your **All in One SEO** setup. Universally takes it from here.', 'universally-language-translation-multilingual-tool'),
                'heroIntro' => __('Reach readers in their own language. Universally translates your site and handles multilingual SEO for you.', 'universally-language-translation-multilingual-tool'),
                'heroBody' => __('Choose from 110+ languages and we’ll translate your entire site, then automatically handle the translated URLs, SEO metadata and hreflang needed to help each version get discovered in search.', 'universally-language-translation-multilingual-tool'),
                'launchWizard' => __('Launch the Setup Wizard', 'universally-language-translation-multilingual-tool'),
                'readGuide' => __('Read The Setup Guide', 'universally-language-translation-multilingual-tool'),
                'dismiss' => __('Dismiss', 'universally-language-translation-multilingual-tool'),
                'opensInNewTab' => __('(opens in a new tab)', 'universally-language-translation-multilingual-tool'),
                'audienceTitle' => __('Reach more of your global audience', 'universally-language-translation-multilingual-tool'),
                'audienceSubtitle' => __('Every language you add opens your site to a new market.', 'universally-language-translation-multilingual-tool'),
                'add' => __('Add', 'universally-language-translation-multilingual-tool'),
                'browseLanguages' => __('Browse All 110+ Languages', 'universally-language-translation-multilingual-tool'),
                'stepsTitle' => __('Go global in minutes', 'universally-language-translation-multilingual-tool'),
                'stepsSubtitle' => __('Translate your site, set up multilingual SEO and go live in just a few steps.', 'universally-language-translation-multilingual-tool'),
                'getStarted' => __('Get Started', 'universally-language-translation-multilingual-tool'),
                'ctaTitle' => __('Ready to reach a global audience?', 'universally-language-translation-multilingual-tool'),
                'ctaSubtitle' => __('Free to try. No card required.', 'universally-language-translation-multilingual-tool'),
                'manualLabel' => __('Already have an API key? Enter it manually', 'universally-language-translation-multilingual-tool'),
            ],
            'languages' => [
                [
                    'flag' => 'es.svg',
                    'name' => __('Spanish', 'universally-language-translation-multilingual-tool'),
                    'tag' => __('High Purchasing Power', 'universally-language-translation-multilingual-tool'),
                    'tone' => 'rose',
                    'users' => __('485 Million Users', 'universally-language-translation-multilingual-tool'),
                ],
                [
                    'flag' => 'de.svg',
                    'name' => __('German', 'universally-language-translation-multilingual-tool'),
                    'tag' => __('Fast Growing Market', 'universally-language-translation-multilingual-tool'),
                    'tone' => 'blue',
                    'users' => __('95 Million Users', 'universally-language-translation-multilingual-tool'),
                ],
                [
                    'flag' => 'fr.svg',
                    'name' => __('French', 'universally-language-translation-multilingual-tool'),
                    'tag' => __('High Purchasing Power', 'universally-language-translation-multilingual-tool'),
                    'tone' => 'rose',
                    'users' => __('80 Million Users', 'universally-language-translation-multilingual-tool'),
                ],
                [
                    'flag' => 'jp.svg',
                    'name' => __('Japanese', 'universally-language-translation-multilingual-tool'),
                    'tag' => __('High Purchasing Power', 'universally-language-translation-multilingual-tool'),
                    'tone' => 'rose',
                    'users' => __('125 Million Users', 'universally-language-translation-multilingual-tool'),
                ],
            ],
            'steps' => [
                [
                    'title' => __('Connect Your Site', 'universally-language-translation-multilingual-tool'),
                    'description' => __('Create your free Universally account to get started.', 'universally-language-translation-multilingual-tool'),
                ],
                [
                    'title' => __('Choose Your Languages', 'universally-language-translation-multilingual-tool'),
                    'description' => __('Choose from 110+ languages and Universally translates your entire website for you.', 'universally-language-translation-multilingual-tool'),
                ],
                [
                    'title' => __('Go Live', 'universally-language-translation-multilingual-tool'),
                    'description' => __('Your translated site goes live, search-ready in every language you chose.', 'universally-language-translation-multilingual-tool'),
                ],
            ],
        ],
    ];
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
    // Re-index so this always JSON-encodes as an array, even after filtering.
    'notices' => array_values($universally_panel_notices),
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
        ...$universally_general_items,
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
            'default' => '#ffffff',
            'sanitize' => 'trim|text_field',
            'separator' => false,
            'palette' => 'background',
        ],
        [
            'id' => 'trigger_text',
            'type' => 'color',
            'label' => __('Text Color', 'universally-language-translation-multilingual-tool'),
            'default' => '#111827',
            'sanitize' => 'trim|text_field',
            'separator' => false,
            'palette' => 'text',
        ],
        [
            'id' => 'trigger_border',
            'type' => 'color',
            'label' => __('Border Color', 'universally-language-translation-multilingual-tool'),
            'default' => '#d1d5db',
            'sanitize' => 'trim|text_field',
            'separator' => false,
            'palette' => 'border',
        ],
        [
            'id' => 'trigger_border_hover',
            'type' => 'color',
            'label' => __('Border Hover', 'universally-language-translation-multilingual-tool'),
            'default' => '#9ca3af',
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
            'default' => '#ffffff',
            'sanitize' => 'trim|text_field',
            'separator' => false,
            'palette' => 'background',
        ],
        [
            'id' => 'dropdown_text',
            'type' => 'color',
            'label' => __('Text Color', 'universally-language-translation-multilingual-tool'),
            'default' => '#111827',
            'sanitize' => 'trim|text_field',
            'separator' => false,
            'palette' => 'text',
        ],
        [
            'id' => 'dropdown_border',
            'type' => 'color',
            'label' => __('Border Color', 'universally-language-translation-multilingual-tool'),
            'default' => '#d1d5db',
            'sanitize' => 'trim|text_field',
            'separator' => false,
            'palette' => 'border',
        ],
        [
            'id' => 'dropdown_hover_bg',
            'type' => 'color',
            'label' => __('Item Hover Background', 'universally-language-translation-multilingual-tool'),
            'default' => '#f3f4f6',
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
            'inlineLabel' => __('Stop browsers from re-translating your translated pages', 'universally-language-translation-multilingual-tool'),
            'description' => __('On your translated pages, browsers like Chrome and Edge may still offer to auto-translate — layering their own lower-quality translation over yours. This adds a notranslate meta tag and the translate="no" attribute so they leave your translations alone.', 'universally-language-translation-multilingual-tool'),
            'default' => false,
            'sanitize' => 'bool',
        ],
        [
            'id' => 'prevent_browser_translation_source',
            'type' => 'toggle',
            'label' => __('Also apply to the original language', 'universally-language-translation-multilingual-tool'),
            'inlineLabel' => __('Prevent browser auto-translation on your original language pages too', 'universally-language-translation-multilingual-tool'),
            'description' => __('Also adds the tags to your original language pages. The trade-off: visitors whose language you haven’t translated into rely on their browser to read your original pages, and this blocks that too. Leave it off to keep the browser as a fallback for languages you don’t offer yet.', 'universally-language-translation-multilingual-tool'),
            'default' => false,
            'sanitize' => 'bool',
            'conditions' => [
                'prevent_browser_translation = true',
            ],
        ],
        [
            'type' => 'section',
            'id' => 'visitor_language_section',
            'label' => __('Visitor Language', 'universally-language-translation-multilingual-tool'),
        ],
        [
            'id' => 'remember_language',
            'type' => 'toggle',
            'label' => __('Remember visitor’s language', 'universally-language-translation-multilingual-tool'),
            'inlineLabel' => __('Send returning visitors to the language they last viewed', 'universally-language-translation-multilingual-tool'),
            'description' => __('When someone opens a translated page, a 30-day cookie stores that language and later visits to your original URLs redirect there. Turn this off if your pages don’t show a language switcher: without one, visitors have no way back to the original language. Turning it off also clears the cookie the next time a visitor opens one of your original URLs.', 'universally-language-translation-multilingual-tool'),
            'default' => true,
            'sanitize' => 'bool',
        ],
        [
            'type' => 'section',
            'id' => 'seo_section',
            'label' => __('SEO', 'universally-language-translation-multilingual-tool'),
        ],
        [
            'id' => 'hreflang_format',
            'type' => 'select',
            'label' => __('Hreflang Format', 'universally-language-translation-multilingual-tool'),
            'description' => __('Region codes target one country: **fr-FR** tells search engines "French for France", which leaves out French speakers in Belgium, Canada and elsewhere. **Language only** serves your single French translation to everyone who speaks it. Pick language only if you keep one translation per language rather than one per country.', 'universally-language-translation-multilingual-tool'),
            'options' => [
                'region' => __('Region codes (fr-FR, pt-BR)', 'universally-language-translation-multilingual-tool'),
                'language' => __('Language only (fr, pt)', 'universally-language-translation-multilingual-tool'),
            ],
            'default' => 'region',
            'sanitize' => 'key',
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
        [
            'type' => 'tab',
            'id' => 'developer_tab',
            'label' => __('Developer', 'universally-language-translation-multilingual-tool'),
            // Kept out of the tabs bar and the sidebar submenu; open it with
            // admin.php?page=universally_settings#developer_tab.
            'hidden' => true,
        ],
        [
            'type' => 'section',
            'id' => 'environment_section',
            'label' => __('Environment', 'universally-language-translation-multilingual-tool'),
            'description' => __('Internal settings. Controls which Universally services this site talks to. Not shown in the panel navigation; open it via #developer_tab.', 'universally-language-translation-multilingual-tool'),
        ],
        [
            'id' => 'environment',
            'type' => 'select',
            'label' => __('Environment', 'universally-language-translation-multilingual-tool'),
            'description' => __('A wp-config constant (UNIVERSALLY_API_URL, UNIVERSALLY_TRANSLATOR_URL, UNIVERSALLY_APP_URL, UNIVERSALLY_SCRIPTS_URL) always overrides this setting.', 'universally-language-translation-multilingual-tool'),
            'options' => [
                'production' => __('Production', 'universally-language-translation-multilingual-tool'),
                'staging' => __('Staging', 'universally-language-translation-multilingual-tool'),
                'local' => __('Local', 'universally-language-translation-multilingual-tool'),
                'custom' => __('Custom', 'universally-language-translation-multilingual-tool'),
            ],
            'default' => 'production',
            'sanitize' => 'key',
        ],
        [
            'id' => 'custom_api_url',
            'type' => 'text',
            'label' => __('API URL', 'universally-language-translation-multilingual-tool'),
            'placeholder' => 'https://api.universally.com',
            'default' => '',
            'sanitize' => 'url',
            'conditions' => [
                'environment = custom',
            ],
        ],
        [
            'id' => 'custom_translator_url',
            'type' => 'text',
            'label' => __('Translator URL', 'universally-language-translation-multilingual-tool'),
            'placeholder' => 'https://translator.universally.com',
            'default' => '',
            'sanitize' => 'url',
            'conditions' => [
                'environment = custom',
            ],
        ],
        [
            'id' => 'custom_app_url',
            'type' => 'text',
            'label' => __('App URL', 'universally-language-translation-multilingual-tool'),
            'placeholder' => 'https://app.universally.com',
            'default' => '',
            'sanitize' => 'url',
            'conditions' => [
                'environment = custom',
            ],
        ],
        [
            'id' => 'custom_scripts_url',
            'type' => 'text',
            'label' => __('Scripts URL', 'universally-language-translation-multilingual-tool'),
            'placeholder' => 'https://scripts.universally.com',
            'default' => '',
            'sanitize' => 'url',
            'conditions' => [
                'environment = custom',
            ],
        ],
    ],
];
