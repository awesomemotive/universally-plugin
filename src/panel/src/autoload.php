<?php
/**
 * WP Panel Autoloader
 *
 * Placeholders replaced during scaffolding:
 * - UniversallyPanel\Panel → PHP namespace (e.g., MyPlugin\Panel)
 * - universally → snake_case prefix for PHP (e.g., my_plugin)
 * - universally → camelCase prefix for JS vars (e.g., myPlugin)
 * - universally → kebab-case prefix for HTML/CSS (e.g., my-plugin)
 */

if (!defined('ABSPATH')) {
    exit;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'UniversallyPanel\Panel\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Load field PHP functions (custom endpoints, validation rules)
$universally_fields_manifest = dirname(__DIR__, 2) . '/fields-manifest.php';
if (file_exists($universally_fields_manifest)) {
    $universally_fields = require $universally_fields_manifest;
    foreach ($universally_fields as $universally_field_file) {
        if (file_exists($universally_field_file)) {
            require_once $universally_field_file;
        }
    }
}
