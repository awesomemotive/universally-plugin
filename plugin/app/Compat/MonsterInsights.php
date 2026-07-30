<?php
/**
 * MonsterInsights (Google Analytics) compatibility.
 *
 * MonsterInsights computes the GA4 `page_location` server-side from
 * home_url( $wp->request ). Universally strips the /{lang}/ prefix from
 * REQUEST_URI before WordPress routes the request, so on translated pages
 * that value is the source-language URL — every translated pageview would be
 * reported to GA4 under the English URL.
 *
 * There is no filter on MonsterInsights' default-locations payload, but when
 * `monsterinsights_is_utm_stripped_server` returns true its inline script
 * assigns `page_location = window.location.href` in the browser instead —
 * which is the localized URL the visitor is actually on. This adapter flips
 * that switch on translated requests only.
 *
 * @package Universally
 */

namespace Universally\Compat;

if (!defined('ABSPATH')) {
    exit;
}

class MonsterInsights implements Integration
{
    public function isActive(): bool
    {
        // Defined by both Lite and Pro on load.
        return defined('MONSTERINSIGHTS_VERSION');
    }

    public function register(string $langCode): void
    {
        add_filter('monsterinsights_is_utm_stripped_server', '__return_true');
    }
}
