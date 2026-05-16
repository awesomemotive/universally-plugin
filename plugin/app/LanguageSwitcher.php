<?php
/**
 * Language Switcher Web Component
 *
 * @package Universally
 */

namespace Universally;

if (!defined('ABSPATH')) {
    exit;
}

class LanguageSwitcher
{
    public function __construct()
    {
        add_shortcode('universally_switcher', [$this, 'renderShortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_footer', [$this, 'renderAutoSwitcher']);
        add_action('init', [$this, 'registerBlock']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueueEditorAssets']);
    }

    public function enqueueAssets(): void
    {
        if ($this->isCurrentPageExcluded()) {
            return;
        }

        wp_enqueue_script(
            'universally-switcher',
            UNIVERSALLY_PLUGIN_URI . 'assets/js/language-switcher.js',
            [],
            UNIVERSALLY_VERSION,
            true
        );
    }

    public function renderShortcode($atts = []): string
    {
        if ($this->isCurrentPageExcluded()) {
            return '';
        }

        // Extract style overrides before shortcode_atts filters them out
        $styleOverrides = $atts['style'] ?? [];
        if (!is_array($styleOverrides)) {
            $styleOverrides = [];
        }

        $atts = shortcode_atts([
            'show_flags' => null,
            'show_names' => null,
            'flag_style' => null,
        ], $atts);

        $overrides = array_filter($atts, fn($v) => $v !== null);

        if (isset($overrides['show_flags'])) {
            $overrides['show_flags'] = filter_var($overrides['show_flags'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($overrides['show_names'])) {
            $overrides['show_names'] = filter_var($overrides['show_names'], FILTER_VALIDATE_BOOLEAN);
        }

        $config = $this->buildConfig($overrides, false);
        if (!$config) return '';

        return $this->renderElement($config, $styleOverrides);
    }

    public function renderAutoSwitcher(): void
    {
        $settings = $this->getSettings();
        if ($settings['implementation'] !== 'auto') return;
        if ($this->isCurrentPageExcluded()) return;

        $config = $this->buildConfig([], true);
        if (!$config) return;

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderElement returns pre-escaped HTML web component
        echo $this->renderElement($config);
    }

    public function registerBlock(): void
    {
        register_block_type(
            UNIVERSALLY_PLUGIN_DIR . 'panel/build/blocks/language-switcher',
            ['render_callback' => [$this, 'renderBlock']]
        );
    }

    public function renderBlock(array $attributes): string
    {
        if ($this->isCurrentPageExcluded()) {
            return '';
        }

        $overrides = [];

        if (!empty($attributes['showFlags'])) {
            $overrides['show_flags'] = $attributes['showFlags'] === 'true';
        }
        if (!empty($attributes['showNames'])) {
            $overrides['show_names'] = $attributes['showNames'] === 'true';
        }
        if (!empty($attributes['flagStyle'])) {
            $overrides['flag_style'] = $attributes['flagStyle'];
        }

        $config = $this->buildConfig($overrides, false);
        if (!$config) return '';

        return $this->renderElement($config);
    }

    public function enqueueEditorAssets(): void
    {
        // Enqueue the Web Component script so the preview renders in the editor
        wp_enqueue_script(
            'universally-switcher',
            UNIVERSALLY_PLUGIN_URI . 'assets/js/language-switcher.js',
            [],
            UNIVERSALLY_VERSION,
            true
        );

        // Localize language data + global settings onto the block's auto-registered
        // editor script handle. WordPress derives this handle from the block name
        // (universally/language-switcher → universally-language-switcher-editor-script).
        // This guarantees the data is inlined before the block's edit.tsx executes.
        $settings = $this->getSettings();

        wp_localize_script(
            'universally-language-switcher-editor-script',
            'universallyBlockData',
            [
                'languages' => array_values(universally_get_switcher_urls()),
                'settings' => [
                    'showFlags' => $settings['show_country_flags'],
                    'showNames' => $settings['show_language_names'],
                    'flagStyle' => $settings['flag_style'],
                ],
                'styleAttr' => $this->buildStyleAttr(),
            ]
        );
    }

    private function buildConfig(array $overrides, bool $fixed): ?array
    {
        $languages = universally_get_switcher_urls();
        if (empty($languages)) return null;

        $settings = $this->getSettings();

        $config = [
            'languages' => array_values($languages),
            'showNames' => $overrides['show_names'] ?? $settings['show_language_names'],
            'showFlags' => $overrides['show_flags'] ?? $settings['show_country_flags'],
            'flagStyle' => $overrides['flag_style'] ?? $settings['flag_style'],
        ];

        if ($fixed) {
            $config['fixed'] = true;
            $config['position'] = $settings['position'];
        }

        return $config;
    }

    private function renderElement(array $config, array $styleOverrides = []): string
    {
        $json = wp_json_encode($config, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE);
        $style = $this->buildStyleAttr($styleOverrides);
        $styleAttr = $style ? " style='" . esc_attr($style) . "'" : '';
        return "<universally-switcher data-config='" . esc_attr($json) . "'" . $styleAttr . "></universally-switcher>";
    }

    private function buildStyleAttr(array $styleOverrides = []): string
    {
        $stored = get_option('universally_settings', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $map = [
            'trigger_bg'           => '--universally-trigger-bg',
            'trigger_text'         => '--universally-trigger-text',
            'trigger_border'       => '--universally-trigger-border',
            'trigger_border_hover' => '--universally-trigger-border-hover',
            'trigger_radius'       => '--universally-trigger-radius',
            'dropdown_bg'          => '--universally-dropdown-bg',
            'dropdown_text'        => '--universally-dropdown-text',
            'dropdown_border'      => '--universally-dropdown-border',
            'dropdown_hover_bg'    => '--universally-dropdown-hover-bg',
            'dropdown_radius'      => '--universally-dropdown-radius',
        ];

        // Merge: style overrides take priority over admin panel defaults
        $merged = array_merge($stored, $styleOverrides);

        $vars = [];
        foreach ($map as $key => $varName) {
            $value = trim($merged[$key] ?? '');
            if ($value !== '') {
                $vars[] = $varName . ':' . $value;
            }
        }

        return implode(';', $vars);
    }

    private function getSettings(): array
    {
        $stored = get_option('universally_settings', []);
        if (!is_array($stored)) $stored = [];

        return [
            'implementation' => $stored['implementation'] ?? 'auto',
            'position' => $stored['position'] ?? 'bottom_right',
            'show_language_names' => $stored['show_language_names'] ?? true,
            'show_country_flags' => $stored['show_country_flags'] ?? true,
            'flag_style' => $stored['flag_style'] ?? 'rounded',
        ];
    }

    private function isCurrentPageExcluded(): bool
    {
        $excludePages = universally_get_exclude_pages();

        if (empty($excludePages)) {
            return false;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- URL is parsed by wp_parse_url; sanitize_text_field would strip percent-encoded UTF-8.
        $currentPath = wp_parse_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $currentPath = rtrim($currentPath ?? '/', '/');

        // Strip language prefix if present (e.g. /fr/checkout -> /checkout)
        $currentLang = universally_get_current_lang();
        if ($currentLang !== null) {
            $currentPath = preg_replace('#^/' . preg_quote($currentLang, '#') . '(/|$)#', '/$1', $currentPath);
            $currentPath = rtrim($currentPath, '/');
        }

        foreach ($excludePages as $pattern) {
            $p = trim($pattern);
            if ($p === '') continue;

            if (substr($p, -2) === '/*') {
                $prefix = rtrim(substr($p, 0, -2), '/');
                if ($currentPath === $prefix || strpos($currentPath, $prefix . '/') === 0) {
                    return true;
                }
            } else {
                if ($currentPath === rtrim($p, '/')) {
                    return true;
                }
            }
        }

        return false;
    }
}
