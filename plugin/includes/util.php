<?php

if (!defined('ABSPATH')) {
    exit;
}

function universally_config($key = null)
{
    static $base_config = null;

    if ($base_config === null) {
        $base_config = include UNIVERSALLY_PLUGIN_DIR . 'config.php';
    }

    /**
     * Filter the config array
     *
     * This filter is applied on every call to allow plugins to modify
     * the configuration at runtime. The base config is cached, but
     * filters are always applied fresh.
     *
     * @param array $config
     */
    $config = apply_filters('universally_config', $base_config);

    if (null !== $key) {
        if (!is_string($key)) {
            return null;
        }

        // if key includes a dot then these are the nested keys, and we need to return the value of the nested key
        // but must fail gracefully if the nested key does not exist
        if (strpos($key, '.') !== false) {
            $keys = explode('.', $key);
            return array_reduce($keys, static function ($value, $nestedKey) {
                return $value[$nestedKey] ?? null;
            }, $config);
        }

        // if key is not nested then return the value of the key or fail gracefully
        return $config[$key] ?? null;
    }

    // if key is not provided then return the whole config
    return $config;
}