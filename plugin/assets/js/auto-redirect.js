/**
 * Universally auto-redirect runtime (browser-locale only).
 *
 * Runs in <head> before paint. Decides whether to redirect the visitor to a
 * translated URL based on (in order):
 *   1) The `universally_auto_lang` cookie (manual user choice — always wins).
 *   2) Whether they're already on a translated URL (no work to do).
 *   3) navigator.languages matched against:
 *      a) Explicit mappings (site_auto_redirect_mapping rows)
 *      b) The site's enabled target languages (RFC 4647 fallback)
 *
 * Reads config from window.universallyAuto, inlined by AutoRedirect.php
 * before this script tag.
 */
(function () {
    var cfg = window.universallyAuto;
    if (!cfg || cfg.enabled !== true) return;

    var COOKIE_NAME = 'universally_auto_lang';
    var BOT_RE = /bot|crawler|spider|crawling|googlebot|bingbot|baiduspider|yandex|duckduckbot|slurp|facebookexternalhit|twitterbot|linkedinbot|whatsapp|telegrambot|discordbot|applebot|ahrefsbot|semrushbot/i;

    var mappings  = (cfg.mappings  && cfg.mappings.length)  ? cfg.mappings  : [];
    var languages = (cfg.languages && cfg.languages.length) ? cfg.languages : [];

    // Defense in depth against /es/es/es/... infinite-redirect loops:
    // derive currentLang from window.location.pathname by matching the first
    // URL segment against the known target-language urlPrefixes.
    var currentLang = cfg.currentLang || currentLangFromPath();

    // ---- Bot skip --------------------------------------------------------
    if (cfg.skipBots && BOT_RE.test(navigator.userAgent || '')) return;

    // ---- Cookie short-circuit --------------------------------------------
    var cookieLang = readCookie();
    if (cookieLang !== null) {
        if (cookieLang === currentLang) return;
        redirectTo(cookieLang);
        return;
    }

    // ---- Already on a translated URL → never auto-redirect away ----------
    if (currentLang) return;

    // ---- Browser-language matching --------------------------------------
    var navLangs = navigator.languages && navigator.languages.length
        ? navigator.languages
        : (navigator.language ? [navigator.language] : []);

    var match = matchLocale(navLangs);
    if (!match) return;  // no match → stay on source

    sendBeacon(match.sourceLocale, match.urlPrefix);
    redirectTo(match.urlPrefix);

    // ---- helpers ---------------------------------------------------------

    /**
     * Walks navigator.languages in priority order. For each browser locale,
     * tries (in order):
     *   1) Exact match against an explicit mapping row
     *   2) Exact match against an enabled target language (region)
     *   3) Language-prefix match against an explicit mapping row
     *   4) Language-prefix match against an enabled target language
     *
     * Returns { sourceLocale, urlPrefix } on first match, else null.
     */
    function matchLocale(navLangs) {
        for (var i = 0; i < navLangs.length; i++) {
            var norm = (navLangs[i] || '').toLowerCase();
            if (!norm) continue;
            var base = norm.split('-')[0];

            // 1) explicit mapping, exact
            for (var j = 0; j < mappings.length; j++) {
                if (mappings[j].sourceLocale === norm) {
                    return { sourceLocale: norm, urlPrefix: mappings[j].targetUrlPrefix };
                }
            }
            // 2) target language, exact region
            for (var k = 0; k < languages.length; k++) {
                if ((languages[k].region || '').toLowerCase() === norm) {
                    return { sourceLocale: norm, urlPrefix: languages[k].urlPrefix };
                }
            }
            // 3) explicit mapping, language-prefix
            for (var l = 0; l < mappings.length; l++) {
                if (mappings[l].sourceLocale.split('-')[0] === base) {
                    return { sourceLocale: norm, urlPrefix: mappings[l].targetUrlPrefix };
                }
            }
            // 4) target language, language-prefix
            for (var m = 0; m < languages.length; m++) {
                var lr = (languages[m].region || '').toLowerCase();
                if (lr.split('-')[0] === base) {
                    return { sourceLocale: norm, urlPrefix: languages[m].urlPrefix };
                }
            }
        }
        return null;
    }

    function currentLangFromPath() {
        var first = (window.location.pathname || '/').split('/').filter(Boolean)[0] || '';
        if (!first) return '';
        var low = first.toLowerCase();
        for (var i = 0; i < languages.length; i++) {
            if ((languages[i].urlPrefix || '').toLowerCase() === low) return low;
        }
        return '';
    }

    /**
     * Fire-and-forget redirect-event beacon. Uses fetch+keepalive so the
     * request survives the navigation that's about to happen. Silent failure —
     * we'd rather lose telemetry than delay the redirect.
     */
    function sendBeacon(sourceLocale, targetPrefix) {
        if (!cfg.beaconEndpoint || typeof fetch !== 'function') return;
        try {
            fetch(cfg.beaconEndpoint, {
                method:      'POST',
                credentials: 'omit',
                keepalive:   true,
                headers: {
                    'Content-Type': 'application/json',
                    'X-API-Key':    cfg.publicKey
                },
                body: JSON.stringify({ sourceLocale: sourceLocale, target: targetPrefix })
            });
        } catch (_) {
            // best-effort
        }
    }

    function redirectTo(prefix) {
        var base = cfg.basePath || '/';
        if (base.charAt(0) !== '/') base = '/' + base;
        var url;
        if (!prefix) {
            url = base;  // source language: no prefix
        } else {
            url = '/' + prefix + (base === '/' ? '/' : base);
        }
        window.location.replace(url);
    }

    function readCookie() {
        var raw = document.cookie || '';
        var pattern = new RegExp('(?:^|;\\s*)' + COOKIE_NAME + '=([^;]*)');
        var m = raw.match(pattern);
        if (!m) return null;
        try {
            return decodeURIComponent(m[1]);
        } catch (_) {
            return m[1] || '';
        }
    }
})();
