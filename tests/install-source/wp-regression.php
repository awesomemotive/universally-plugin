<?php
/**
 * Exercise first-activation attribution and hosted URL output in real WordPress.
 * Run with the adjacent Playground blueprint; no hosted app requests are made.
 */
require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

/** Collect behavioral failures so one run diagnoses all attribution regressions. */
function universally_source_expect($actual, $expected, string $label): void
{
    $GLOBALS['universally_source_results'][] = [
        'case' => $label,
        'passed' => $actual === $expected,
        'expected' => $expected,
        'actual' => $actual,
    ];
}

/** Read the public hosted-connect URL as a client would. */
function universally_source_from_url(): string
{
    static $onboarding;
    if (!$onboarding) {
        $onboarding = new Universally\Onboarding();
    }
    parse_str(wp_parse_url($onboarding->buildConnectUrl(), PHP_URL_QUERY), $args);
    return $args['source'];
}

/** Run WordPress's activation lifecycle using a controlled, same-site referrer. */
function universally_source_activate(string $referrer, bool $fresh = true): void
{
    if ($fresh) {
        delete_option('universally_install_source');
        delete_option('universally_installed_by');
    }
    $_REQUEST['_wp_http_referer'] = $referrer;
    $_SERVER['HTTP_REFERER'] = $referrer;
    $_SERVER['REQUEST_URI'] = '/?universally-source-regression';
    $plugin = 'universally-language-translation-multilingual-tool/universally.php';
    deactivate_plugins($plugin, true);
    $result = activate_plugin($plugin);
    if (is_wp_error($result)) {
        throw new RuntimeException($result->get_error_message());
    }
    delete_transient('universally_onboarding_redirect');
}

$GLOBALS['universally_source_results'] = [];
foreach ([
    ['admin.php?page=aioseo-setup-wizard', 'wp-plugin.aioseo'],
    ['admin.php?page=monsterinsights_settings', 'wp-plugin.monsterinsights'],
    ['admin.php?page=wpforms-setup', 'wp-plugin.wpforms'],
    ['admin.php?page=optinmonster', 'wp-plugin.optinmonster'],
    ['admin.php?page=seedprod_lite', 'wp-plugin.seedprod'],
    ['options-general.php?page=wp-mail-smtp', 'wp-plugin.wpmailsmtp'],
    ['admin.php?page=duplicator-pro', 'wp-plugin.duplicator'],
    ['admin.php?page=wpconsent', 'wp-plugin.wpconsent'],
    ['plugin-install.php?tab=search&s=universally', 'wp-plugin.search'],
    ['plugins.php?action=activate', 'wp-plugin.plugins-screen'],
    ['network/plugin-install.php?tab=search&s=wpforms', 'wp-plugin.search'],
    ['network/plugins.php', 'wp-plugin.plugins-screen'],
    ['plugin-install.php?tab=search&s=wpforms', 'wp-plugin.search'],
    ['plugins.php?s=aioseo', 'wp-plugin.plugins-screen'],
    ['admin.php?page=unrelated&return=plugin-install.php', 'wp-plugin'],
    ['admin.php?page=unrelated&return=aioseo', 'wp-plugin'],
    ['admin.php?page[]=aioseo', 'wp-plugin'],
    ['admin.php?page=notwpforms', 'wp-plugin'],
] as [$route, $expected]) {
    universally_source_activate(admin_url($route));
    universally_source_expect(universally_source_from_url(), $expected, $route);
}
universally_source_activate(home_url('/aioseo/plugin-install.php'));
universally_source_expect(universally_source_from_url(), 'wp-plugin', 'non-admin URL');
universally_source_activate('');
universally_source_expect(universally_source_from_url(), 'wp-plugin', 'missing referrer');
universally_source_activate(admin_url('admin.php?page=aioseo-setup-wizard'));
universally_source_activate(admin_url('plugins.php'), false);
universally_source_expect(universally_source_from_url(), 'wp-plugin.aioseo', 'first activation wins');

update_option('universally_installed_by', 'AIOSEO_Setup Wizard!');
universally_source_expect(universally_source_from_url(), 'wp-plugin.aioseo_setupwizard', 'late partner option wins and normalizes');
update_option('universally_installed_by', str_repeat('Placement_', 20));
$source = universally_source_from_url();
universally_source_expect(strlen($source), 64, 'partner placement bounded to 64 bytes');
universally_source_expect(preg_match('/^[a-z0-9_.-]{1,64}$/D', $source), 1, 'hosted source contract');
foreach (['!!!', ['aioseo']] as $invalidPartner) {
    update_option('universally_installed_by', $invalidPartner);
    universally_source_expect(universally_source_from_url(), 'wp-plugin.aioseo', 'invalid partner uses recorded source');
}
delete_option('universally_installed_by');
foreach (['', ['aioseo'], 'Upper case!', str_repeat('a', 65)] as $invalidRecorded) {
    update_option('universally_install_source', $invalidRecorded);
    universally_source_expect(universally_source_from_url(), 'wp-plugin', 'invalid recorded source falls back');
}
delete_option('universally_install_source');
universally_source_expect(universally_source_from_url(), 'wp-plugin', 'existing install without attribution');

foreach (['DOING_AJAX', 'REST_REQUEST', 'WP_CLI'] as $context) {
    if (!defined($context)) {
        define($context, true);
    }
    universally_source_activate(admin_url('admin.php?page=wpforms-setup'));
    universally_source_expect(universally_source_from_url(), 'wp-plugin.wpforms', $context . ' activation records source');
}

$results = $GLOBALS['universally_source_results'];
file_put_contents('/wordpress/qa-results/install-source.json', wp_json_encode([
    'wp' => get_bloginfo('version'),
    'php' => PHP_VERSION,
    'results' => $results,
], JSON_PRETTY_PRINT));
foreach ($results as $result) {
    if (!$result['passed']) {
        throw new RuntimeException('Attribution regression: ' . $result['case']);
    }
}
echo count($results) . " install-source assertions passed\n";
