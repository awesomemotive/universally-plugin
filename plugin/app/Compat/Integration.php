<?php
/**
 * Contract for third-party plugin compatibility adapters.
 *
 * Each supported plugin gets one adapter class. Adapters are registered by
 * Compat\Manager on translated requests only ({@see UnifiedBuffer::setup()}),
 * so hooks added in register() never run on source-language pages.
 *
 * @package Universally
 */

namespace Universally\Compat;

if (!defined('ABSPATH')) {
    exit;
}

interface Integration
{
    /**
     * Whether the third-party plugin this adapter targets is active.
     */
    public function isActive(): bool;

    /**
     * Attach the adapter's hooks. Called at init (priority 1) on translated
     * requests, after UNIVERSALLY_CURRENT_LANG is defined.
     *
     * @param string $langCode Current URL language code (e.g. "es").
     */
    public function register(string $langCode): void;
}
