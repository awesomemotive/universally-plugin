<?php
/**
 * SaaS onboarding launch + return handler.
 *
 * Onboarding lives entirely in the Universally app. The plugin is a thin client:
 *
 *   1. On first activation it sends the admin to an in-WP connect page (kept in
 *      wp-admin so they aren't bounced off-site unexpectedly). From there the
 *      "Connect to Universally" button launches the hosted flow at
 *      UNIVERSALLY_APP_URL/connect, passing the site URL, a return URL, and a
 *      short-lived `state` nonce (CSRF).
 *   2. The hosted flow handles account / plan / languages, then redirects back
 *      to that same connect page with `?activation_token=…&state=…`.
 *   3. The callback validates `state`, then runs the existing activation
 *      exchange → commit (see ActivationToken). The API key is fetched
 *      server-side and never reaches the browser — the page JS only ever sees
 *      an opaque exchangeId + display info.
 *
 * @package Universally
 */

namespace Universally;

if (!defined('ABSPATH')) {
    exit;
}

class Onboarding
{
    /** Transient that flags a pending post-activation redirect. */
    private const REDIRECT_FLAG = 'universally_onboarding_redirect';

    /** Transient holding the round-trip CSRF nonce. */
    private const STATE_KEY = 'universally_connect_state';

    /** State TTL — long enough to span the whole hosted onboarding flow. */
    private const STATE_TTL = 3600;

    /** Option recording how the plugin got installed; set once, on first activation. */
    private const INSTALL_SOURCE_OPTION = 'universally_install_source';

    /** Value sent when we cannot tell how the plugin was installed. */
    private const INSTALL_SOURCE_FALLBACK = 'wp-plugin';

    /**
     * Option a partner installer (AIOSEO, MonsterInsights, …) writes to name the
     * placement that triggered the install, e.g. `aioseo_setup_wizard`.
     */
    private const INSTALLED_BY_OPTION = 'universally_installed_by';

    /** Upper bound the hosted flow accepts for `source`. */
    private const SOURCE_MAX_LEN = 64;

    /** Hidden admin page slug used as the hosted-flow return target. */
    public const CALLBACK_SLUG = 'universally-connect';

    public function __construct()
    {
        register_activation_hook(UNIVERSALLY_PLUGIN_FILE, [$this, 'recordInstallSource']);
        register_activation_hook(UNIVERSALLY_PLUGIN_FILE, [$this, 'scheduleRedirect']);
        add_action('admin_init', [$this, 'maybeRedirect']);
        add_action('admin_menu', [$this, 'registerCallbackPage']);
        add_action('current_screen', [$this, 'setCallbackPageTitle']);
        add_action('current_screen', [$this, 'suppressAdminNotices']);
    }

    /**
     * Strip admin notices from the connect screen.
     *
     * This is a standalone, full-height branded page. Notices from core (the
     * update nag) or other plugins render above our shell inside #wpbody-content
     * and break the layout. current_screen fires before admin-header.php runs the
     * notice hooks, so removing them here prevents the notices at the source
     * rather than hiding them with CSS.
     *
     * @param \WP_Screen $screen
     */
    public function suppressAdminNotices($screen): void
    {
        if (!$screen || strpos((string) $screen->id, self::CALLBACK_SLUG) === false) {
            return;
        }

        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
        remove_all_actions('user_admin_notices');
        remove_all_actions('network_admin_notices');
    }

    /**
     * Set the page title for the (parentless) callback screen before the admin
     * header renders. Without this, WordPress core's get_admin_page_title()
     * leaves the global $title null and admin-header.php throws a strip_tags()
     * deprecation on PHP 8.1+.
     *
     * @param \WP_Screen $screen
     */
    public function setCallbackPageTitle($screen): void
    {
        if ($screen && strpos((string) $screen->id, self::CALLBACK_SLUG) !== false) {
            // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional: core leaves $title null on this parentless page, triggering a strip_tags() deprecation in admin-header.php on PHP 8.1+.
            $GLOBALS['title'] = __('Connecting to Universally', 'universally-language-translation-multilingual-tool');
        }
    }

    /**
     * Record how this install arrived, on first activation only.
     *
     * Deliberately has no AJAX/REST/CLI guard, unlike scheduleRedirect: an
     * auto-install from another Awesome Motive plugin's wizard IS a programmatic
     * activation, and that is exactly the case we are trying to identify. Stored
     * in an option rather than a transient because connecting can happen days
     * after activation, and first install wins so a deactivate/reactivate cycle
     * cannot rewrite the original provenance.
     */
    public function recordInstallSource(): void
    {
        if (get_option(self::INSTALL_SOURCE_OPTION, '') !== '') {
            return;
        }

        update_option(self::INSTALL_SOURCE_OPTION, $this->detectInstallSource(), false);
    }

    /**
     * The `source` value for the hosted connect flow.
     *
     * A partner's explicit placement beats our own referer sniffing: it says
     * *where inside* AIOSEO or MonsterInsights the install was triggered, which the
     * referer cannot. Read at connect time rather than in the activation hook
     * because the partner may write the option after our activation already ran.
     * Namespaced under the fallback so it still groups as a plugin install.
     */
    private function connectSource(): string
    {
        $installedBy = get_option(self::INSTALLED_BY_OPTION, '');
        if (is_string($installedBy) && $installedBy !== '') {
            $normalized = preg_replace('/[^a-z0-9_.-]/', '', strtolower($installedBy));
            if (is_string($normalized) && $normalized !== '') {
                return substr(self::INSTALL_SOURCE_FALLBACK . '.' . $normalized, 0, self::SOURCE_MAX_LEN);
            }
        }

        return (string) get_option(self::INSTALL_SOURCE_OPTION, self::INSTALL_SOURCE_FALLBACK);
    }

    /**
     * Best-effort provenance from the admin page that triggered the activation.
     *
     * Values stay within the hosted flow's `source` contract (lowercase, dots and
     * dashes, max 32 chars) so they land in acquisition reporting as-is.
     *
     * ponytail: referer sniffing, not a handshake. It cannot see an install done
     * over WP-CLI or by a host's bulk provisioner, which both report the
     * fallback. If the AM installers ever pass an explicit source param, read
     * that here first and keep this as the fallback.
     */
    private function detectInstallSource(): string
    {
        $referer = wp_get_referer();
        if (!is_string($referer) || $referer === '') {
            return self::INSTALL_SOURCE_FALLBACK;
        }

        $installers = [
            'aioseo'          => 'wp-plugin.aioseo',
            'monsterinsights' => 'wp-plugin.monsterinsights',
            'wpforms'         => 'wp-plugin.wpforms',
            'optinmonster'    => 'wp-plugin.optinmonster',
            'seedprod'        => 'wp-plugin.seedprod',
            'wp-mail-smtp'    => 'wp-plugin.wpmailsmtp',
            'duplicator'      => 'wp-plugin.duplicator',
            'wpconsent'       => 'wp-plugin.wpconsent',
        ];

        foreach ($installers as $needle => $source) {
            if (strpos($referer, $needle) !== false) {
                return $source;
            }
        }

        // Plain WordPress routes, checked after the installers: an AM wizard runs
        // its install through admin-ajax, so its referer never looks like these.
        if (strpos($referer, 'plugin-install.php') !== false) {
            return 'wp-plugin.search';
        }
        if (strpos($referer, 'plugins.php') !== false) {
            return 'wp-plugin.plugins-screen';
        }

        return self::INSTALL_SOURCE_FALLBACK;
    }

    /**
     * Flag a one-shot redirect on fresh activation.
     *
     * Skips bulk/network activations and sites that are already connected so we
     * never hijack an upgrade or a multi-plugin activation.
     */
    public function scheduleRedirect(): void
    {
        // Only arm the redirect for a genuine, user-initiated activation from the
        // Plugins screen. Programmatic activations (REST / AJAX / WP-CLI) — e.g.
        // another plugin's setup wizard or a host's bulk installer — must not
        // hijack that flow with our onboarding redirect.
        if (
            wp_doing_ajax()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('WP_CLI') && WP_CLI)
        ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only bulk-activation guard mirroring WP core's own `activate-multi` check; no state change.
        if (is_network_admin() || isset($_GET['activate-multi'])) {
            return;
        }
        if (universally_get_api_key() !== '') {
            return;
        }
        set_transient(self::REDIRECT_FLAG, true, 60);
    }

    /**
     * Consume the flag on the next admin page load and redirect to the hosted flow.
     */
    public function maybeRedirect(): void
    {
        if (wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON)) {
            return;
        }
        if (!get_transient(self::REDIRECT_FLAG)) {
            return;
        }
        // One-shot: clear before redirecting so it never loops.
        delete_transient(self::REDIRECT_FLAG);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only bulk-activation guard mirroring WP core's own `activate-multi` check; no state change.
        if (is_network_admin() || isset($_GET['activate-multi'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        if (universally_get_api_key() !== '') {
            return;
        }

        // Land on the in-WP connect page rather than bouncing straight to the
        // hosted app — the admin stays in WordPress and chooses when to continue
        // (the connect page's "Connect to Universally" button launches the flow).
        wp_safe_redirect(admin_url('admin.php?page=' . self::CALLBACK_SLUG));
        exit;
    }

    /**
     * Register the hidden callback page (no parent → not shown in any menu).
     */
    public function registerCallbackPage(): void
    {
        add_submenu_page(
            '',
            __('Connecting to Universally', 'universally-language-translation-multilingual-tool'),
            __('Connecting to Universally', 'universally-language-translation-multilingual-tool'),
            'manage_options',
            self::CALLBACK_SLUG,
            [$this, 'renderCallback']
        );
    }

    /**
     * Build the hosted onboarding URL, persisting a round-trip state nonce.
     *
     * Targets /connect/account directly: the Welcome step is replicated in the
     * plugin's own connect screen (see renderLanding), so the hosted flow starts
     * at the account step. The anonymous-usage consent chosen in WP travels as the
     * `usage` param and is echoed back to the plugin on return.
     *
     * The state is reused while it's still valid (only generated when absent) so
     * that rendering the "Connect" button on the settings page — which can happen
     * repeatedly, even mid-flow — never rotates the nonce out from under an
     * in-progress connection. The TTL is always refreshed so the window slides
     * forward on each build.
     */
    public function buildConnectUrl(): string
    {
        $state = get_transient(self::STATE_KEY);
        if (!is_string($state) || $state === '') {
            $state = wp_generate_password(32, false);
        }
        set_transient(self::STATE_KEY, $state, self::STATE_TTL);

        $args = [
            'site_url'    => home_url(),
            'site_name'   => get_bloginfo('name'),
            'site_locale' => get_locale(),
            'return_url'  => admin_url('admin.php?page=' . self::CALLBACK_SLUG),
            'state'       => $state,
            'source'      => $this->connectSource(),
            'v'           => '1',
            'usage'       => $this->usageConsentDefault() ? '1' : '0',
        ];

        return add_query_arg($args, $this->appBase() . '/connect/account');
    }

    /**
     * The site's current anonymous-usage-tracking preference, used as the default
     * for the connect consent checkbox and as the outbound `usage` value.
     *
     * Defaults to opt-in (true) when unset — matching the usage_tracking setting's
     * own default in the Preferences tab.
     */
    private function usageConsentDefault(): bool
    {
        $settings = get_option(UNIVERSALLY_SETTINGS_KEY, []);

        if (is_array($settings) && array_key_exists('usage_tracking', $settings)) {
            return (bool) $settings['usage_tracking'];
        }

        return true;
    }

    /**
     * Render the return target: connecting / connected / error / landing.
     */
    public function renderCallback(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'universally-language-translation-multilingual-tool'));
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- this IS the nonce check: `state` is a custom CSRF nonce validated below via hash_equals() against a server-side transient. A WP nonce can't be used because the round-trip originates off-site (the hosted flow), not from a WP-rendered form.
        $token = isset($_GET['activation_token'])
            ? sanitize_text_field(wp_unslash($_GET['activation_token']))
            : '';
        $state = isset($_GET['state'])
            ? sanitize_text_field(wp_unslash($_GET['state']))
            : '';

        // Validate the round-trip nonce server-side before doing anything.
        // The state is NOT single-use: the hosted "Congratulations" screen can
        // return here via more than one action (Dashboard / Settings), and the
        // real credential (the activation token) is single-use server-side. The
        // state simply expires with its transient TTL.
        $stored = get_transient(self::STATE_KEY);
        $valid  = $token !== ''
            && $state !== ''
            && is_string($stored)
            && hash_equals($stored, $state);

        // Persist the anonymous-usage-data choice made during onboarding. Stored
        // in the panel option so the "Settings" tab toggle reflects/edits it.
        if ($valid && isset($_GET['usage_tracking'])) {
            $settings = get_option(UNIVERSALLY_SETTINGS_KEY, []);
            if (!is_array($settings)) {
                $settings = [];
            }
            $settings['usage_tracking'] = sanitize_text_field(wp_unslash($_GET['usage_tracking'])) === '1';
            update_option(UNIVERSALLY_SETTINGS_KEY, $settings);
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $isWelcome = ($token === '');

        $this->renderShell(function () use ($token, $valid): void {
            if ($token === '') {
                $this->renderLanding();
            } elseif (!$valid) {
                $this->renderError(
                    __('This connection link is invalid or has expired. Please try connecting again.', 'universally-language-translation-multilingual-tool')
                );
            } else {
                $this->renderConnecting($token);
            }
        }, $isWelcome);
    }

    /* ------------------------------------------------------------------ */
    /* Rendering                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Full-width branded shell around the callback content.
     *
     * @param callable $content Echoes the inner content.
     * @param bool     $welcome Welcome step — widen the column and drop the small
     *                          wordmark (the hero illustration already carries the
     *                          brand mark).
     */
    private function renderShell(callable $content, bool $welcome = false): void
    {
        $logo        = UNIVERSALLY_PLUGIN_URI . 'assets/logo-full-dark.svg';
        $settingsUrl = admin_url('admin.php?page=' . UNIVERSALLY_SETTINGS_KEY);
        $host        = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $innerClass  = 'uvly-connect__inner' . ($welcome ? ' uvly-connect__inner--welcome' : '');
        ?>
        <div class="uvly-connect">
            <?php if ($welcome) : ?>
                <div class="uvly-connect__progress"><span class="uvly-connect__progress-fill"></span></div>
            <?php endif; ?>

            <header class="uvly-connect__header">
                <img class="uvly-connect__logo" src="<?php echo esc_url($logo); ?>" alt="Universally" />
                <div class="uvly-connect__header-right">
                    <span class="uvly-connect__site">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><path d="M2 12h20"></path><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                        <span class="uvly-connect__site-label"><?php esc_html_e('Connecting to', 'universally-language-translation-multilingual-tool'); ?></span>
                        <span class="uvly-connect__site-host"><?php echo esc_html($host); ?></span>
                    </span>
                    <a class="uvly-connect__close" href="<?php echo esc_url($settingsUrl); ?>" aria-label="<?php esc_attr_e('Cancel and return to settings', 'universally-language-translation-multilingual-tool'); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </a>
                </div>
            </header>

            <main class="uvly-connect__main">
                <div class="<?php echo esc_attr($innerClass); ?>">
                    <div class="uvly-connect__content">
                        <?php $content(); ?>
                    </div>
                </div>
            </main>
        </div>
        <style>
            /* Full-screen takeover — hide the WP admin chrome so this reads like
               the app's standalone connect screen. Scoped: this <style> is printed
               only on the connect page. */
            #adminmenumain, #adminmenuback, #adminmenuwrap, #wpadminbar, #wpfooter { display: none !important; }
            html.wp-toolbar { padding-top: 0 !important; }
            html { margin-top: 0 !important; }
            #wpcontent, #wpbody, #wpbody-content { margin-left: 0 !important; padding: 0 !important; }
            #wpwrap, #wpbody, body.wp-admin { background: #fff; }

            .uvly-connect {
                position: relative;
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                background: #fff;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            }
            .uvly-connect__progress { height: 3px; width: 100%; background: rgba(124, 58, 237, 0.15); }
            .uvly-connect__progress-fill { display: block; height: 100%; width: 10%; background: #7c3aed; }
            .uvly-connect__header {
                display: flex; align-items: center; justify-content: space-between; gap: 16px;
                height: 56px; padding: 0 20px; border-bottom: 1px solid #ececf1; background: #fff;
            }
            .uvly-connect__logo { height: 22px; width: auto; display: block; }
            .uvly-connect__header-right { display: flex; align-items: center; gap: 12px; font-size: 13px; color: #5d5573; }
            .uvly-connect__site { display: inline-flex; align-items: center; gap: 7px; }
            .uvly-connect__site svg { width: 15px; height: 15px; opacity: 0.7; }
            .uvly-connect__site-host { font-weight: 600; color: #1a1333; }
            .uvly-connect__close {
                display: inline-flex; align-items: center; justify-content: center;
                width: 32px; height: 32px; border-radius: 6px; color: #5d5573;
                transition: background 0.15s ease, color 0.15s ease;
            }
            .uvly-connect__close:hover { background: #f3f0fa; color: #1a1333; }
            .uvly-connect__close svg { width: 18px; height: 18px; }
            .uvly-connect__main { flex: 1 1 auto; display: flex; align-items: center; justify-content: center; padding: 40px 20px; }
            .uvly-connect__inner {
                width: 100%; max-width: 520px; text-align: center;
                animation: uvly-rise 0.5s cubic-bezier(0.16, 0.84, 0.44, 1) both;
            }
            .uvly-connect__inner--welcome { max-width: 760px; }
            @keyframes uvly-rise { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
            /* Welcome: hero centered in the upper area, consent settling near the bottom. */
            .uvly-connect__welcome { display: flex; flex-direction: column; min-height: 66vh; }
            .uvly-connect__welcome-hero { flex: 1 1 auto; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 26px; }
            .uvly-connect__welcome-text h1 { margin: 0 0 12px; }
            .uvly-connect__welcome-text p { margin: 0 auto; }
            .uvly-connect__content h1 {
                font-size: 30px; line-height: 1.15; font-weight: 800; letter-spacing: -0.02em;
                color: #1a1333; margin: 0 0 14px;
            }
            .uvly-connect__content p {
                font-size: 15px; line-height: 1.65; color: #5d5573;
                margin: 0 auto 28px; max-width: 30em;
            }
            .uvly-connect__btn {
                display: inline-flex; align-items: center; justify-content: center;
                background: linear-gradient(135deg, #7c3aed 0%, #650cdf 100%);
                color: #fff; border: 0; border-radius: 12px; padding: 15px 34px;
                font-size: 15px; font-weight: 700; text-decoration: none; cursor: pointer;
                box-shadow: 0 12px 28px -10px rgba(101, 12, 223, 0.65);
                transition: transform 0.15s ease, box-shadow 0.15s ease;
            }
            .uvly-connect__btn:hover { transform: translateY(-2px); color: #fff; box-shadow: 0 18px 36px -10px rgba(101, 12, 223, 0.75); }
            .uvly-connect__btn:active { transform: translateY(0); }
            /* WP admin's global `a:focus { border-radius: 2px }` squares the button
               on click/focus — keep our radius and give a proper focus ring. */
            .uvly-connect__btn:focus,
            .uvly-connect__btn:focus-visible,
            .uvly-connect__btn:active { border-radius: 12px; }
            .uvly-connect__btn:focus-visible { outline: 2px solid #7c3aed; outline-offset: 3px; }
            .uvly-connect__close:focus,
            .uvly-connect__close:focus-visible { border-radius: 6px; }
            .uvly-connect__link {
                display: inline-block; margin-top: 20px; color: #7c3aed;
                font-size: 13px; text-decoration: none; font-weight: 500;
            }
            .uvly-connect__link:hover { color: #650cdf; text-decoration: underline; }
            /* Welcome step: hero illustration, CTA arrow, and usage-consent row. */
            .uvly-connect__globe {
                display: block; width: 100%; max-width: 640px; height: auto;
                margin: 0 auto;
            }
            .uvly-connect__btn svg { margin-left: 8px; width: 16px; height: 16px; }
            .uvly-connect__consent {
                position: relative; max-width: 620px; margin: 44px auto 0; text-align: left;
            }
            .uvly-connect__consent-input {
                position: absolute; width: 1px; height: 1px; margin: 0; padding: 0;
                opacity: 0; pointer-events: none;
            }
            .uvly-connect__consent-label {
                display: flex; align-items: flex-start; gap: 10px; cursor: pointer;
                font-size: 13px; line-height: 1.6; color: #5d5573;
            }
            .uvly-connect__consent-box {
                flex: 0 0 auto; width: 20px; height: 20px; margin-top: 1px;
                display: inline-flex; align-items: center; justify-content: center;
                border: 1.5px solid #cfc6e0; border-radius: 6px; background: #fff;
                color: #7c3aed; transition: border-color 0.15s ease, box-shadow 0.15s ease;
            }
            .uvly-connect__consent-box svg { width: 13px; height: 13px; opacity: 0; }
            .uvly-connect__consent-input:checked + .uvly-connect__consent-label .uvly-connect__consent-box { border-color: #7c3aed; }
            .uvly-connect__consent-input:checked + .uvly-connect__consent-label .uvly-connect__consent-box svg { opacity: 1; }
            .uvly-connect__consent-input:focus-visible + .uvly-connect__consent-label .uvly-connect__consent-box { box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.25); }
            .uvly-connect__consent-link { color: #7c3aed; text-decoration: underline; text-underline-offset: 2px; }
            .uvly-connect__consent-link:hover { color: #650cdf; }
            .uvly-connect__spinner {
                width: 30px; height: 30px; margin: 4px auto 18px;
                border: 3px solid rgba(124, 58, 237, 0.25); border-top-color: #7c3aed;
                border-radius: 50%; animation: uvly-spin 0.8s linear infinite;
            }
            @keyframes uvly-spin { to { transform: rotate(360deg); } }
            .uvly-connect__ok { color: #00a32a; font-weight: 700; }
            .uvly-connect__err { color: #d63638; }
            .uvly-connect--hidden { display: none; }
            @media (max-width: 600px) { .uvly-connect__inner { padding: 32px 20px; } .uvly-connect__content h1 { font-size: 25px; } }
            @media (prefers-reduced-motion: reduce) { .uvly-connect__inner { animation: none; } }
        </style>
        <?php
    }

    /**
     * No token present — the replicated Welcome step. Mirrors the app's
     * /connect Welcome screen (hero illustration, heading, CTA, and the
     * anonymous-usage consent). "Let's Get Started" carries the consent choice
     * as `usage` and lands on /connect/account, skipping the app's own Welcome.
     */
    private function renderLanding(): void
    {
        $connectUrl = $this->buildConnectUrl();
        $globe      = UNIVERSALLY_PLUGIN_URI . 'assets/welcome-globe.png';
        $consent    = $this->usageConsentDefault();
        ?>
        <div class="uvly-connect__welcome">
            <div class="uvly-connect__welcome-hero">
                <img class="uvly-connect__globe" src="<?php echo esc_url($globe); ?>"
                     alt="<?php esc_attr_e('Universally connects your site to readers around the world', 'universally-language-translation-multilingual-tool'); ?>" />

                <div class="uvly-connect__welcome-text">
                    <h1><?php esc_html_e('Welcome to Universally', 'universally-language-translation-multilingual-tool'); ?></h1>
                    <p>
                        <?php esc_html_e('Increase your global reach with Universally.', 'universally-language-translation-multilingual-tool'); ?>
                        <br>
                        <?php esc_html_e('Complete the process in just 5 minutes!', 'universally-language-translation-multilingual-tool'); ?>
                    </p>
                </div>

                <a id="uvly-cta" class="uvly-connect__btn" href="<?php echo esc_url($connectUrl); ?>">
                    <?php esc_html_e('Let’s Get Started', 'universally-language-translation-multilingual-tool'); ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </a>
            </div>

            <div class="uvly-connect__consent">
                <input type="checkbox" id="uvly-usage" class="uvly-connect__consent-input" <?php checked($consent); ?> />
                <label for="uvly-usage" class="uvly-connect__consent-label">
                    <span class="uvly-connect__consent-box" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    </span>
                    <span>
                        <?php esc_html_e('I agree to share anonymous usage data to help make Universally better for everyone. You can opt out at any time in Universally settings.', 'universally-language-translation-multilingual-tool'); ?>
                        <a id="uvly-usage-link" class="uvly-connect__consent-link" href="https://universally.com/docs/usage-tracking-in-wordpress/" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e('Learn more about anonymous usage tracking.', 'universally-language-translation-multilingual-tool'); ?>
                        </a>
                    </span>
                </label>
            </div>
        </div>

        <script>
        (function () {
            var cb  = document.getElementById('uvly-usage');
            var cta = document.getElementById('uvly-cta');
            if (cb && cta) {
                var sync = function () {
                    try {
                        var u = new URL(cta.href);
                        u.searchParams.set('usage', cb.checked ? '1' : '0');
                        cta.href = u.toString();
                    } catch (e) {}
                };
                cb.addEventListener('change', sync);
                sync();
            }
            // Keep the "Learn more" link from toggling the checkbox via the label.
            var link = document.getElementById('uvly-usage-link');
            if (link) {
                link.addEventListener('click', function (e) { e.stopPropagation(); });
            }
        })();
        </script>
        <?php
    }

    /**
     * Token + valid state — auto-run exchange → commit. The key stays server-side;
     * this JS only handles the opaque exchangeId and display info.
     */
    private function renderConnecting(string $token): void
    {
        $restRoot    = esc_url_raw(rest_url('universally/v1/'));
        $nonce       = wp_create_nonce('wp_rest');
        $settingsUrl = admin_url('admin.php?page=' . UNIVERSALLY_SETTINGS_KEY);
        $retryUrl    = admin_url('admin.php?page=' . self::CALLBACK_SLUG);
        ?>
        <div id="uvly-step-connecting">
            <div class="uvly-connect__spinner"></div>
            <h1><?php esc_html_e('Connecting your site…', 'universally-language-translation-multilingual-tool'); ?></h1>
            <p><?php esc_html_e('Finishing the secure handshake with Universally.', 'universally-language-translation-multilingual-tool'); ?></p>
        </div>

        <div id="uvly-step-error" class="uvly-connect--hidden">
            <h1><?php esc_html_e('We couldn’t finish connecting', 'universally-language-translation-multilingual-tool'); ?></h1>
            <p class="uvly-connect__err" id="uvly-error-detail"></p>
            <a class="uvly-connect__btn" href="<?php echo esc_url($retryUrl); ?>">
                <?php esc_html_e('Try again', 'universally-language-translation-multilingual-tool'); ?>
            </a>
            <br>
            <a class="uvly-connect__link" href="<?php echo esc_url($settingsUrl); ?>">
                <?php esc_html_e('Or enter your API key manually', 'universally-language-translation-multilingual-tool'); ?>
            </a>
        </div>

        <script>
        (function () {
            var root  = <?php echo wp_json_encode($restRoot); ?>;
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var token = <?php echo wp_json_encode($token); ?>;

            function show(id) {
                ['uvly-step-connecting', 'uvly-step-error'].forEach(function (s) {
                    document.getElementById(s).classList.toggle('uvly-connect--hidden', s !== id);
                });
            }
            function fail(msg) {
                document.getElementById('uvly-error-detail').textContent =
                    msg || <?php echo wp_json_encode(__('Something went wrong. Please try again.', 'universally-language-translation-multilingual-tool')); ?>;
                show('uvly-step-error');
            }
            function post(path, body) {
                return fetch(root + path, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                    body: JSON.stringify(body)
                }).then(function (r) { return r.json(); });
            }

            post('activation/exchange', { token: token }).then(function (ex) {
                if (!ex || !ex.success) { return fail(ex && ex.message); }
                return post('activation/commit', { exchangeId: ex.exchangeId }).then(function (co) {
                    if (!co || !co.success) { return fail(co && co.message); }
                    // Connected — go straight to the Universally dashboard.
                    window.location.href = <?php echo wp_json_encode($settingsUrl); ?>;
                });
            }).catch(function () {
                fail(<?php echo wp_json_encode(__('Network error. Please try again.', 'universally-language-translation-multilingual-tool')); ?>);
            });
        })();
        </script>
        <?php
    }

    private function renderError(string $message): void
    {
        $retryUrl = admin_url('admin.php?page=' . self::CALLBACK_SLUG);
        ?>
        <h1><?php esc_html_e('Connection link expired', 'universally-language-translation-multilingual-tool'); ?></h1>
        <p class="uvly-connect__err"><?php echo esc_html($message); ?></p>
        <a class="uvly-connect__btn" href="<?php echo esc_url($retryUrl); ?>">
            <?php esc_html_e('Try again', 'universally-language-translation-multilingual-tool'); ?>
        </a>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function appBase(): string
    {
        return universally_get_app_url();
    }

}
