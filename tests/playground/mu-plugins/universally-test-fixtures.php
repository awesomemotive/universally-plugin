<?php
/**
 * Plugin Name: Universally Test Fixtures
 * Description: Test-only. Seeds a language set and lets requests override the
 *              remember-language setting via headers. Mounted by the Playground
 *              e2e suite in tests/playground; never ships with the plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('universally_languages', function ($languages) {
    // Add to the list rather than replace it: the blueprint's inline fixture
    // seeds en/es, which lang-endpoints.spec.ts depends on. We only need pt.
    $languages = is_array($languages) ? $languages : [];

    if (empty($languages)) {
        $languages[] = [
            'name' => 'English',
            'originalName' => 'English',
            'region' => 'US',
            'flagUrl' => '',
            'lang' => 'en',
            'variant' => 'en-US',
            'urlPrefix' => '',
            'isSource' => true,
            'isDisabled' => false,
        ];
    }

    foreach ($languages as $language) {
        if (($language['urlPrefix'] ?? '') === 'pt') {
            return $languages;
        }
    }

    $languages[] = [
        'name' => 'Portuguese',
        'originalName' => 'Português',
        'region' => 'BR',
        'flagUrl' => '',
        'lang' => 'pt',
        'variant' => 'pt-BR',
        'urlPrefix' => 'pt',
        'isSource' => false,
        'isDisabled' => false,
    ];

    return $languages;
});

/**
 * X-Universally-Test-Remember: 0|1 — force the stored setting value.
 * Handles both the saved and the never-saved option states.
 */
$universallyTestRemember = isset($_SERVER['HTTP_X_UNIVERSALLY_TEST_REMEMBER'])
    ? (string) $_SERVER['HTTP_X_UNIVERSALLY_TEST_REMEMBER']
    : null;

if ($universallyTestRemember !== null) {
    $override = function ($settings) use ($universallyTestRemember) {
        if (!is_array($settings)) {
            $settings = [];
        }
        $settings['remember_language'] = $universallyTestRemember === '1';
        return $settings;
    };
    add_filter('option_universally_settings', $override);
    add_filter('default_option_universally_settings', $override);
}

/**
 * X-Universally-Test-Filter: off — exercise the developer filter path.
 */
if (isset($_SERVER['HTTP_X_UNIVERSALLY_TEST_FILTER']) && $_SERVER['HTTP_X_UNIVERSALLY_TEST_FILTER'] === 'off') {
    add_filter('universally_remember_language', '__return_false');
}
