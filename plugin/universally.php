<?php
/**
 * Plugin Name: Universally Language Translation Multilingual Tool
 * Plugin URI: https://universally.com
 * Description: Automatic website translation and localization for WordPress.
 * Version: 1.0.6
 * Author: Syed Balkhi
 * Author URI: https://universally.com/
 * Text Domain: universally-language-translation-multilingual-tool
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

use Universally\ActivationToken;
use Universally\AdminBar;
use Universally\LanguageSwitcher;
use Universally\Migration;
use Universally\Onboarding;
use Universally\RestApi;
use Universally\UnifiedBuffer;

if (!defined('ABSPATH')) {
    exit;
}

// If the legacy plugin (universally/universally.php) was loaded earlier in the
// same request, its classes already live in the Universally\ namespace.
// Bail out now: re-running our bootstrap would either redeclare classes or
// silently use the old plugin's code. The conflict handler removed the old
// slug from active_plugins, so on the next request only this plugin loads.
if (class_exists('Universally\\AdminBar', false)) {
    require_once __DIR__ . '/includes/conflict-handler.php';
    return;
}

const UNIVERSALLY_PLUGIN_FILE = __FILE__;
const UNIVERSALLY_VERSION = '1.0.6';
const UNIVERSALLY_SETTINGS_KEY = 'universally_settings';

register_activation_hook(__FILE__, function () {
    require_once __DIR__ . '/includes/conflict-handler.php';
});

define('UNIVERSALLY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('UNIVERSALLY_PLUGIN_URI', plugin_dir_url(__FILE__));

// API endpoints
if (!defined('UNIVERSALLY_API_URL')) {
    define('UNIVERSALLY_API_URL', 'https://api.universally.com');
}

if (!defined('UNIVERSALLY_TRANSLATOR_URL')) {
    define('UNIVERSALLY_TRANSLATOR_URL', 'https://translator.universally.com');
}

if (!defined('UNIVERSALLY_DEBUG')) {
    define('UNIVERSALLY_DEBUG', false);
}

// Must run before autoloads — deactivates the legacy "universally/universally.php"
// install if both are present, since they share the Universally\ namespace.
require_once __DIR__ . '/includes/conflict-handler.php';

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/panel/src/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/util.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/notice.php';
require_once __DIR__ . '/includes/entry.php';

new UnifiedBuffer();
(new Migration())->run();
new LanguageSwitcher();
new AdminBar();
new RestApi();
new ActivationToken();
new Onboarding();
