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

    /** Hidden admin page slug used as the hosted-flow return target. */
    public const CALLBACK_SLUG = 'universally-connect';

    public function __construct()
    {
        register_activation_hook(UNIVERSALLY_PLUGIN_FILE, [$this, 'scheduleRedirect']);
        add_action('admin_init', [$this, 'maybeRedirect']);
        add_action('admin_menu', [$this, 'registerCallbackPage']);
        add_action('current_screen', [$this, 'setCallbackPageTitle']);
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
            'source'      => 'wp-plugin',
            'v'           => '1',
        ];

        return add_query_arg($args, $this->appBase() . '/connect');
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
        });
    }

    /* ------------------------------------------------------------------ */
    /* Rendering                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Full-width branded shell around the callback content.
     *
     * @param callable $content Echoes the inner content.
     */
    private function renderShell(callable $content): void
    {
        $logo = UNIVERSALLY_PLUGIN_URI . 'assets/logo-full-dark.svg';
        ?>
        <div class="uvly-connect">
            <div class="uvly-connect__bg" aria-hidden="true"></div>
            <div class="uvly-connect__inner">
                <img class="uvly-connect__brand" src="<?php echo esc_url($logo); ?>" alt="Universally" />
                <div class="uvly-connect__content">
                    <?php $content(); ?>
                </div>
            </div>
        </div>
        <style>
            /* Scoped to this page only — the style block is printed solely on the
               connect screen, so it's safe to take over the admin content area. */
            #wpcontent, #wpbody, #wpbody-content { padding: 0 !important; }
            #wpfooter { display: none; }
            .uvly-connect {
                position: relative;
                min-height: calc(100vh - 32px);
                display: flex;
                align-items: center;
                justify-content: center;
                overflow: hidden;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                background:
                    radial-gradient(45rem 45rem at 12% 8%, rgba(124, 58, 237, 0.20), transparent 60%),
                    radial-gradient(40rem 40rem at 88% 92%, rgba(101, 12, 223, 0.18), transparent 55%),
                    linear-gradient(180deg, #faf9ff 0%, #f1ebfe 100%);
            }
            .uvly-connect__bg { position: absolute; inset: 0; pointer-events: none; }
            .uvly-connect__bg::before,
            .uvly-connect__bg::after {
                content: ""; position: absolute; border-radius: 50%;
                filter: blur(80px); opacity: 0.45;
            }
            .uvly-connect__bg::before { width: 360px; height: 360px; background: #a78bfa; top: -90px; left: -70px; }
            .uvly-connect__bg::after { width: 460px; height: 460px; background: #7c3aed; bottom: -140px; right: -90px; opacity: 0.3; }
            .uvly-connect__inner {
                position: relative; z-index: 1;
                width: 100%; max-width: 520px;
                padding: 48px 40px; text-align: center;
                animation: uvly-rise 0.5s cubic-bezier(0.16, 0.84, 0.44, 1) both;
            }
            @keyframes uvly-rise { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
            .uvly-connect__brand { height: 36px; width: auto; margin-bottom: 32px; filter: drop-shadow(0 6px 16px rgba(101, 12, 223, 0.18)); }
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
            .uvly-connect__link {
                display: inline-block; margin-top: 20px; color: #7c3aed;
                font-size: 13px; text-decoration: none; font-weight: 500;
            }
            .uvly-connect__link:hover { color: #650cdf; text-decoration: underline; }
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
     * No token present — let the admin (re)launch the hosted flow or fall back
     * to pasting a key manually.
     */
    private function renderLanding(): void
    {
        $connectUrl  = $this->buildConnectUrl();
        $settingsUrl = admin_url('admin.php?page=' . UNIVERSALLY_SETTINGS_KEY);
        ?>
        <h1><?php esc_html_e('Connect your site to start translating', 'universally-language-translation-multilingual-tool'); ?></h1>
        <p><?php esc_html_e('Reach a global audience by translating your site into 110+ languages, automatically. Setup takes about a minute.', 'universally-language-translation-multilingual-tool'); ?></p>
        <a class="uvly-connect__btn" href="<?php echo esc_url($connectUrl); ?>">
            <?php esc_html_e('Connect to Universally', 'universally-language-translation-multilingual-tool'); ?>
        </a>
        <br>
        <a class="uvly-connect__link" href="<?php echo esc_url($settingsUrl); ?>">
            <?php esc_html_e('Already have an API key? Enter it manually', 'universally-language-translation-multilingual-tool'); ?>
        </a>
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
