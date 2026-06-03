<?php
/**
 * SaaS onboarding launch + return handler.
 *
 * Onboarding lives entirely in the Universally app. The plugin is a thin client:
 *
 *   1. On first activation (and on demand) it redirects the admin OUT to the
 *      hosted flow at UNIVERSALLY_APP_URL/connect, passing the site URL, a
 *      return URL, and a single-use `state` nonce.
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

    /** State TTL — matches the activation-token TTL on the API side. */
    private const STATE_TTL = 600;

    /** Hidden admin page slug used as the hosted-flow return target. */
    public const CALLBACK_SLUG = 'universally-connect';

    private const TEXT_DOMAIN = 'universally-language-translation-multilingual-tool';

    public function __construct()
    {
        register_activation_hook(UNIVERSALLY_PLUGIN_FILE, [$this, 'scheduleRedirect']);
        add_action('admin_init', [$this, 'maybeRedirect']);
        add_action('admin_menu', [$this, 'registerCallbackPage']);
    }

    /**
     * Flag a one-shot redirect on fresh activation.
     *
     * Skips bulk/network activations and sites that are already connected so we
     * never hijack an upgrade or a multi-plugin activation.
     */
    public function scheduleRedirect(): void
    {
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
            __('Connecting to Universally', self::TEXT_DOMAIN),
            '',
            'manage_options',
            self::CALLBACK_SLUG,
            [$this, 'renderCallback']
        );
    }

    /**
     * Build the hosted onboarding URL and persist a fresh state nonce.
     */
    public function buildConnectUrl(): string
    {
        $state = wp_generate_password(32, false);
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
            wp_die(esc_html__('You do not have permission to access this page.', self::TEXT_DOMAIN));
        }

        $token = isset($_GET['activation_token'])
            ? sanitize_text_field(wp_unslash($_GET['activation_token']))
            : '';
        $state = isset($_GET['state'])
            ? sanitize_text_field(wp_unslash($_GET['state']))
            : '';

        // Validate the round-trip nonce server-side before doing anything.
        $stored = get_transient(self::STATE_KEY);
        $valid  = $token !== ''
            && $state !== ''
            && is_string($stored)
            && hash_equals($stored, $state);

        // State is single-use the moment a token comes back.
        if ($token !== '') {
            delete_transient(self::STATE_KEY);
        }

        $this->renderShell(function () use ($token, $valid): void {
            if ($token === '') {
                $this->renderLanding();
            } elseif (!$valid) {
                $this->renderError(
                    __('This connection link is invalid or has expired. Please try connecting again.', self::TEXT_DOMAIN)
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
        <h1><?php esc_html_e('Connect your site to Universally', self::TEXT_DOMAIN); ?></h1>
        <p><?php esc_html_e('Create your account, choose a plan, and pick your languages — then we’ll bring you right back here, connected.', self::TEXT_DOMAIN); ?></p>
        <a class="uvly-connect__btn" href="<?php echo esc_url($connectUrl); ?>">
            <?php esc_html_e('Connect to Universally', self::TEXT_DOMAIN); ?>
        </a>
        <br>
        <a class="uvly-connect__link" href="<?php echo esc_url($settingsUrl); ?>">
            <?php esc_html_e('Already have an API key? Enter it manually', self::TEXT_DOMAIN); ?>
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
            <h1><?php esc_html_e('Connecting your site…', self::TEXT_DOMAIN); ?></h1>
            <p><?php esc_html_e('Finishing the secure handshake with Universally.', self::TEXT_DOMAIN); ?></p>
        </div>

        <div id="uvly-step-done" class="uvly-connect--hidden">
            <h1 class="uvly-connect__ok"><?php esc_html_e('Connected ✓', self::TEXT_DOMAIN); ?></h1>
            <p id="uvly-done-detail"></p>
            <a class="uvly-connect__btn" href="<?php echo esc_url($settingsUrl); ?>">
                <?php esc_html_e('Go to Settings', self::TEXT_DOMAIN); ?>
            </a>
        </div>

        <div id="uvly-step-error" class="uvly-connect--hidden">
            <h1><?php esc_html_e('We couldn’t finish connecting', self::TEXT_DOMAIN); ?></h1>
            <p class="uvly-connect__err" id="uvly-error-detail"></p>
            <a class="uvly-connect__btn" href="<?php echo esc_url($retryUrl); ?>">
                <?php esc_html_e('Try again', self::TEXT_DOMAIN); ?>
            </a>
            <br>
            <a class="uvly-connect__link" href="<?php echo esc_url($settingsUrl); ?>">
                <?php esc_html_e('Or enter your API key manually', self::TEXT_DOMAIN); ?>
            </a>
        </div>

        <script>
        (function () {
            var root  = <?php echo wp_json_encode($restRoot); ?>;
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var token = <?php echo wp_json_encode($token); ?>;

            function show(id) {
                ['uvly-step-connecting', 'uvly-step-done', 'uvly-step-error'].forEach(function (s) {
                    document.getElementById(s).classList.toggle('uvly-connect--hidden', s !== id);
                });
            }
            function fail(msg) {
                document.getElementById('uvly-error-detail').textContent =
                    msg || <?php echo wp_json_encode(__('Something went wrong. Please try again.', self::TEXT_DOMAIN)); ?>;
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
                    var info = (ex.displayInfo) || {};
                    var detail = info.workspaceName
                        ? <?php echo wp_json_encode(__('Connected to', self::TEXT_DOMAIN)); ?> + ' ' + info.workspaceName
                        : '';
                    document.getElementById('uvly-done-detail').textContent = detail;
                    show('uvly-step-done');
                });
            }).catch(function () {
                fail(<?php echo wp_json_encode(__('Network error. Please try again.', self::TEXT_DOMAIN)); ?>);
            });
        })();
        </script>
        <?php
    }

    private function renderError(string $message): void
    {
        $retryUrl = admin_url('admin.php?page=' . self::CALLBACK_SLUG);
        ?>
        <h1><?php esc_html_e('Connection link expired', self::TEXT_DOMAIN); ?></h1>
        <p class="uvly-connect__err"><?php echo esc_html($message); ?></p>
        <a class="uvly-connect__btn" href="<?php echo esc_url($retryUrl); ?>">
            <?php esc_html_e('Try again', self::TEXT_DOMAIN); ?>
        </a>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function appBase(): string
    {
        return rtrim(UNIVERSALLY_APP_URL, '/');
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
