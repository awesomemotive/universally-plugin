<?php
/**
 * Admin bar menu for Universally
 *
 * Adds a top-level admin bar node with quick links and
 * a conditional usage limit warning.
 *
 * @package Universally
 */

namespace Universally;

if (!defined('ABSPATH')) {
    exit;
}

class AdminBar
{
    public function __construct()
    {
        add_action('admin_bar_menu', [$this, 'addMenu'], 81);
        add_action('wp_enqueue_scripts', [$this, 'enqueueStyles']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueStyles']);
    }

    public function addMenu(\WP_Admin_Bar $adminBar): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $limitReached = (bool) get_transient('universally_limit_reached');

        if ($limitReached) {
            $adminBar->add_node([
                'id'     => 'universally-adminbar-limit-warning',
                'parent' => 'universally',
                'title'  => '<div class="universally-adminbar-alert">'
                    . '<div><span class="universally-adminbar-alert__text">' . esc_html__('Usage limit reached', 'universally-language-translation-multilingual-tool') . '</span></div>'
                    . '<div><span class="universally-adminbar-alert__cta">' . esc_html__('Upgrade', 'universally-language-translation-multilingual-tool') . ' &rarr;</span></div>'
                    . '</div>',
                'href'   => universally_get_app_url() . '/billing/',
                'meta'   => [
                    'class'  => 'universally-adminbar-limit-warning',
                    'target' => '_blank',
                ],
            ]);
        }

        $adminBar->add_node([
            'id'    => 'universally',
            'title' => $this->getMenuTitle($limitReached),
            'href'  => admin_url('admin.php?page=' . UNIVERSALLY_SETTINGS_KEY),
            'meta'  => [
                'title' => __('Universally', 'universally-language-translation-multilingual-tool'),
            ],
        ]);

        $adminBar->add_node([
            'id'     => 'universally-general-settings',
            'parent' => 'universally',
            'title'  => __('General Settings', 'universally-language-translation-multilingual-tool'),
            'href'   => admin_url('admin.php?page=' . UNIVERSALLY_SETTINGS_KEY),
        ]);

        $adminBar->add_node([
            'id'     => 'universally-settings-language-switcher',
            'parent' => 'universally',
            'title'  => __('Language Switcher', 'universally-language-translation-multilingual-tool'),
            'href'   => admin_url('admin.php?page=' . UNIVERSALLY_SETTINGS_KEY . '#language_switcher_tab'),
        ]);

        $adminBar->add_node([
            'id'     => 'universally-settings-styling',
            'parent' => 'universally',
            'title'  => __('Styling', 'universally-language-translation-multilingual-tool'),
            'href'   => admin_url('admin.php?page=' . UNIVERSALLY_SETTINGS_KEY . '#styling_tab'),
        ]);

        $adminBar->add_node([
            'id'     => 'universally-dashboard',
            'parent' => 'universally',
            'title'  => __('App Dashboard', 'universally-language-translation-multilingual-tool'),
            'href'   => universally_get_app_url() . '/',
            'meta'   => ['target' => '_blank'],
        ]);

        $adminBar->add_node([
            'id'     => 'universally-docs',
            'parent' => 'universally',
            'title'  => __('Docs', 'universally-language-translation-multilingual-tool'),
            'href'   => 'https://universally.com/docs/',
            'meta'   => ['target' => '_blank'],
        ]);
    }

    private function getMenuTitle(bool $limitReached): string
    {
        $iconClass = 'universally-adminbar-icon'
            . ($limitReached ? ' universally-adminbar-icon--alert' : '');

        return '<span class="' . $iconClass . '"></span>';
    }

    public function enqueueStyles(): void
    {
        if (!is_admin_bar_showing() || !current_user_can('manage_options')) {
            return;
        }

        wp_enqueue_style(
            'universally-adminbar',
            UNIVERSALLY_PLUGIN_URI . 'assets/css/admin-bar.css',
            [],
            UNIVERSALLY_VERSION
        );
    }
}
