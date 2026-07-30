<?php
/**
 * Registers third-party compatibility adapters on translated requests.
 *
 * @package Universally
 */

namespace Universally\Compat;

if (!defined('ABSPATH')) {
    exit;
}

class Manager
{
    /**
     * All known adapters. Add new plugin integrations here.
     *
     * @return Integration[]
     */
    private function integrations(): array
    {
        return [
            new WooCommerce(),
            new MonsterInsights(),
        ];
    }

    /**
     * Register every adapter whose target plugin is active.
     *
     * @param string $langCode Current URL language code (e.g. "es").
     */
    public function register(string $langCode): void
    {
        foreach ($this->integrations() as $integration) {
            if ($integration->isActive()) {
                $integration->register($langCode);
            }
        }
    }
}
