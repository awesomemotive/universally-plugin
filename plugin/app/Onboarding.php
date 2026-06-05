<?php
/**
 * SaaS onboarding launch + return handler.
 *
 * Onboarding lives entirely in the Universally app. The plugin is a thin client:
 *
 *   1. On first activation (and on demand) it redirects the admin OUT to the
 *      hosted flow at UNIVERSALLY_APP_URL/connect, passing the site URL, a
 *      return URL, and a short-lived `state` nonce (CSRF).
 *   2. The hosted flow handles account / plan / languages, then redirects back
 *      to a hidden admin callback page with `?activation_token=…&state=…`.
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

        $this->allowAppHost();
        wp_safe_redirect($this->buildConnectUrl());
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
        ?>
        <div class="uvly-connect">
            <div class="uvly-connect__brand">Universally</div>
            <div class="uvly-connect__card">
                <?php $content(); ?>
            </div>
        </div>
        <style>
            .uvly-connect { max-width: 640px; margin: 64px auto; text-align: center; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
            .uvly-connect__brand { font-size: 22px; font-weight: 700; color: #650cdf; margin-bottom: 28px; }
            .uvly-connect__card { background: #fff; border: 1px solid #e2e4e7; border-radius: 8px; padding: 40px; }
            .uvly-connect__card h1 { font-size: 22px; margin: 0 0 10px; }
            .uvly-connect__card p { color: #50575e; font-size: 14px; margin: 0 0 8px; }
            .uvly-connect__btn { display: inline-block; margin-top: 20px; background: #650cdf; color: #fff; border: 0; border-radius: 6px; padding: 12px 28px; font-size: 14px; font-weight: 600; text-decoration: none; cursor: pointer; }
            .uvly-connect__btn:hover { background: #6f1edd; color: #fff; }
            .uvly-connect__link { display: inline-block; margin-top: 16px; color: #650cdf; font-size: 13px; }
            .uvly-connect__spinner { width: 28px; height: 28px; margin: 8px auto 16px; border: 3px solid #d6bcfa; border-top-color: #650cdf; border-radius: 50%; animation: uvly-spin .8s linear infinite; }
            @keyframes uvly-spin { to { transform: rotate(360deg); } }
            .uvly-connect__ok { color: #00a32a; font-weight: 600; }
            .uvly-connect__err { color: #d63638; }
            .uvly-connect--hidden { display: none; }
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
        <h1><?php esc_html_e('Connect your site to Universally', 'universally-language-translation-multilingual-tool'); ?></h1>
        <p><?php esc_html_e('Create your account, choose a plan, and pick your languages — then we’ll bring you right back here, connected.', 'universally-language-translation-multilingual-tool'); ?></p>
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

    /**
     * Whitelist the app host so wp_safe_redirect() will allow the off-site jump.
     */
    private function allowAppHost(): void
    {
        add_filter('allowed_redirect_hosts', function (array $hosts): array {
            $host = wp_parse_url($this->appBase(), PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[] = $host;
            }
            return $hosts;
        });
    }
}
