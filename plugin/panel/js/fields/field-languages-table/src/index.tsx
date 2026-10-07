import {useState, useEffect, useRef} from 'react';
import apiFetch from '@wordpress/api-fetch';
import {Modal} from '@wordpress/components';
import {useFieldApi} from './useFieldApi';

/**
 * Translated UI strings, passed from PHP (includes/settings.php). Counts and
 * names are filled in here with `fmt()`; plural forms come in pairs.
 */
interface LanguagesStrings {
    countNone?: string;
    addedOne?: string;
    addedMany?: string;
    liveOne?: string;
    liveMany?: string;
    refresh?: string;
    manage?: string;
    opensInNewTab?: string;
    loading?: string;
    sourceIs?: string;
    emptyTitle?: string;
    emptyText?: string;
    browseAll?: string;
    speakersMillion?: string;
    speakersBillion?: string;
    add?: string;
    adding?: string;
    added?: string;
    addLanguage?: string;
    addFailed?: string;
    tagHighPurchasingPower?: string;
    tagFastGrowingMarket?: string;
    colLanguage?: string;
    colUrl?: string;
    colStatus?: string;
    source?: string;
    statusLive?: string;
    statusDisabled?: string;
    copyUrl?: string;
    copied?: string;
    suggestedTitle?: string;
    suggestedText?: string;
    allLanguages?: string;
    upgradeLabel?: string;
    upgradeTitle?: string;
    upgradeText?: string;
    upgradeCta?: string;
    upgradeBonus?: string;
    maybeLater?: string;
    close?: string;
}

interface FieldConfig {
    endpoint: string;
    /** Universally app base URL (honors the wp-config override). */
    appUrl?: string;
    /** Connected project id; enables a deep-link to its language panel. */
    projectId?: string;
    /**
     * Whether the site holds an API key at page load. The api-key field's
     * connection event keeps this current afterwards (see CONNECTION_EVENT).
     */
    connected?: boolean;
    strings?: LanguagesStrings;
    /** Translated suggestion names, keyed by backend variant code. */
    languageNames?: Record<string, string>;

    [key: string]: unknown;
}

interface Props {
    fieldId: string;
    config: FieldConfig;
    value: string;
    onChange: (value: string) => void;
}

interface LanguageItem {
    name: string
    originalName: string
    region: string
    flagUrl: string
    lang: string
    variant: string
    urlPrefix: string
    isSource: boolean
    isDisabled?: boolean
}

interface LanguagesResponse {
    sourceLanguage: string;
    languages: LanguageItem[];
}

const cache = new Map<string, LanguagesResponse>();

// Dispatched on window by field-api-key whenever the site connects or
// disconnects, as `{ detail: { connected: boolean } }`. Fields are
// self-contained, so this event is the contract between the two.
const CONNECTION_EVENT = 'universally:connection';

interface AddLanguageResponse {
    success: boolean;
    code?: string;
    message?: string;
    languages?: LanguageItem[];
}

interface PopularLanguage {
    /** Backend variant code (lowercase) passed to POST /connect/languages. */
    variant: string;
    /** ISO country code for the flag (the language's default region). */
    cc: string;
    /** Native-speaker count, sourced from packages/languages (LANGS nativeSpeakers). */
    speakers: number;
    /** Market badge — mirrors the hosted onboarding's framing. */
    tone: 'rose' | 'blue';
}

// Flag SVGs served from the Universally CDN, e.g. .../flags/es.svg.
const FLAG_CDN = 'https://cdn.universally.com/flags';

// At most this many suggestions are shown at once; adding one reveals the next.
// Empty state: tiles. Under the table: the lighter "Suggested next" pills.
const MAX_SUGGESTIONS = 4;
const MAX_PILLS = 3;

// How long a just-added tile stays green before the table replaces the tiles.
const ADDED_FLASH_MS = 1200;

// Popular languages offered for one-click add. Variant codes must match the
// backend catalog (packages/languages) exactly, or the add is rejected. Speaker
// counts mirror that package's `nativeSpeakers`; tones mirror the hosted
// onboarding's framing (rose = high purchasing power, blue = fast-growing
// market); `cc` is the default region's flag code. Names come from PHP
// (`languageNames`) so they can be translated.
const POPULAR_LANGUAGES: PopularLanguage[] = [
    {variant: 'es', cc: 'es', speakers: 485_000_000, tone: 'rose'},
    {variant: 'zh-hans', cc: 'cn', speakers: 920_000_000, tone: 'blue'},
    {variant: 'ar', cc: 'sa', speakers: 310_000_000, tone: 'blue'},
    {variant: 'pt-br', cc: 'br', speakers: 260_000_000, tone: 'blue'},
    {variant: 'ja', cc: 'jp', speakers: 125_000_000, tone: 'rose'},
    {variant: 'de', cc: 'de', speakers: 95_000_000, tone: 'blue'},
    {variant: 'fr', cc: 'fr', speakers: 80_000_000, tone: 'rose'},
    {variant: 'it', cc: 'it', speakers: 67_000_000, tone: 'rose'},
    {variant: 'nl', cc: 'nl', speakers: 25_000_000, tone: 'rose'},
];

// Minimal sprintf: fills %s / %d (and positional %1$s) in order.
function fmt(template: string | undefined, ...args: (string | number)[]): string {
    if (!template) return '';
    let i = 0;
    return template.replace(/%(?:(\d+)\$)?[sd]/g, (_m, pos?: string) => {
        const v = pos ? args[Number(pos) - 1] : args[i++];
        return v === undefined ? '' : String(v);
    });
}

// Speaker count spelled out, e.g. 485000000 -> "485 million speakers",
// 1.2e9 -> "1.2 billion speakers".
function formatSpeakers(n: number, s: LanguagesStrings): string {
    if (n >= 1_000_000_000) {
        const b = (n / 1_000_000_000).toFixed(2).replace(/\.?0+$/, '');
        return fmt(s.speakersBillion, b);
    }
    return fmt(s.speakersMillion, Math.round(n / 1_000_000));
}

// Build a language's live URL from its prefix. Source (empty prefix) -> site root.
// `display` drops the scheme for a compact, readable address; `full` is what we
// link to and copy.
function buildLangUrl(urlPrefix: string): {full: string; display: string} {
    const origin = window.location.origin;
    const path = urlPrefix ? `/${urlPrefix}/` : '/';
    return {full: origin + path, display: origin.replace(/^https?:\/\//, '') + path};
}

const RefreshIcon = ({className}: {className?: string}) => (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className={className}>
        <path d="M21 12a9 9 0 0 1-15.5 6.2L3 16"/>
        <path d="M3 12a9 9 0 0 1 15.5-6.2L21 8"/>
        <path d="M21 3v5h-5"/>
        <path d="M3 21v-5h5"/>
    </svg>
);

const SpinnerIcon = () => <RefreshIcon className="wp-panel-languages-table__spin"/>;

const PlusIcon = () => (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" aria-hidden="true">
        <path d="M12 5v14M5 12h14"/>
    </svg>
);

const ExternalIcon = () => (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M7 17 17 7M8 7h9v9"/>
    </svg>
);

const ArrowIcon = () => (
    <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path d="M11.3 4.3 10.2 5.4l3.8 3.8H3.5v1.6H14l-3.8 3.8 1.1 1.1L17 10z"/>
    </svg>
);

const CheckIcon = () => (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M20 6 9 17l-5-5"/>
    </svg>
);

export function LanguagesTableField({fieldId, config}: Props) {
    const s = config.strings ?? {};
    const names = config.languageNames ?? {};
    const cached = cache.get(config.endpoint);
    const [data, setData] = useState<LanguagesResponse | null>(cached ?? null);
    // Which row's URL was just copied — drives the transient "copied" checkmark.
    const [copiedKey, setCopiedKey] = useState<string | null>(null);
    // Add-language state: which variant is in flight, an inline error, and the
    // plan-limit upsell modal.
    const [addingVariant, setAddingVariant] = useState<string | null>(null);
    const [addError, setAddError] = useState<string | null>(null);
    const [planLimitOpen, setPlanLimitOpen] = useState(false);
    // Empty state only: the tile that was just added stays green ("Added") for
    // a moment before the fresh list swaps the tiles for the table.
    const [justAdded, setJustAdded] = useState<string | null>(null);
    const addedTimer = useRef<number | undefined>(undefined);
    // Connection state: seeded by the server, then driven by the api-key field.
    // There is no project to read or add languages to while disconnected.
    const [connected, setConnected] = useState(config.connected ?? true);
    const {loading, error, request} = useFieldApi<LanguagesResponse>(config.endpoint);

    useEffect(() => () => window.clearTimeout(addedTimer.current), []);

    const targets = (data?.languages ?? []).filter((l) => !l.isSource);
    const isEmpty = targets.length === 0;

    const addLanguage = async (variant: string) => {
        // Captured now: the tiles get the "Added" flash, the pills don't.
        const fromEmpty = isEmpty;
        setAddingVariant(variant);
        setAddError(null);
        try {
            const res = await apiFetch<AddLanguageResponse>({
                path: config.endpoint,
                method: 'POST',
                data: {variant},
            });
            if (res?.success && res.languages) {
                const next: LanguagesResponse = {
                    sourceLanguage: data?.sourceLanguage ?? '',
                    languages: res.languages,
                };
                cache.set(config.endpoint, next);
                if (fromEmpty) {
                    setJustAdded(variant);
                    window.clearTimeout(addedTimer.current);
                    addedTimer.current = window.setTimeout(() => {
                        setJustAdded(null);
                        setData(next);
                    }, ADDED_FLASH_MS);
                } else {
                    setData(next);
                }
            } else if (res?.code === 'PLAN_LIMIT_REACHED') {
                setPlanLimitOpen(true);
            } else {
                setAddError(res?.message || s.addFailed || '');
            }
        } catch (e) {
            setAddError(e instanceof Error ? e.message : s.addFailed || '');
        } finally {
            setAddingVariant(null);
        }
    };

    const copyUrl = async (key: string, url: string) => {
        try {
            await navigator.clipboard.writeText(url);
            setCopiedKey(key);
            window.setTimeout(() => setCopiedKey((k) => (k === key ? null : k)), 1500);
        } catch {
            // Clipboard blocked (insecure context / denied) — no-op; the link is still clickable.
        }
    };

    // Languages are managed in the app dashboard. When the connected project id
    // is known, deep-link straight to its language panel; otherwise fall back to
    // the dashboard root (which lands the user on their project).
    const appUrl = config.appUrl || 'https://app.universally.com';
    const dashboardUrl = config.projectId
        ? `${appUrl}/projects/${config.projectId}/languages`
        : appUrl;
    // Manage-plan page — where the app's own upgrade/upsell CTAs point.
    const upgradeUrl = `${appUrl.replace(/\/$/, '')}/billing/manage`;

    const refresh = async () => {
        cache.delete(config.endpoint);
        const res = await request('GET');
        if (res) {
            cache.set(config.endpoint, res);
            setData(res);
        }
    };

    // Follow Connect / Disconnect from the api-key field without a reload. Either
    // way the old project's list is meaningless: drop it so the empty state shows
    // straight away, and on reconnect the fetch below re-runs against the new one.
    useEffect(() => {
        const onConnection = (e: Event) => {
            const next = Boolean((e as CustomEvent<{connected?: boolean}>).detail?.connected);
            cache.delete(config.endpoint);
            // A pending "Added" flash would otherwise restore the old list.
            window.clearTimeout(addedTimer.current);
            setJustAdded(null);
            setData(null);
            setAddError(null);
            setPlanLimitOpen(false);
            setConnected(next);
        };
        window.addEventListener(CONNECTION_EVENT, onConnection);
        return () => window.removeEventListener(CONNECTION_EVENT, onConnection);
    }, [config.endpoint]);

    useEffect(() => {
        if (!connected || cache.has(config.endpoint)) return;
        // A response that lands after the connection changed belongs to the old
        // project — ignore it rather than cache it.
        let stale = false;
        const fetch = async () => {
            const res = await request('GET');
            if (res && !stale) {
                cache.set(config.endpoint, res);
                setData(res);
            }
        };
        fetch();
        return () => {
            stale = true;
        };
    }, [request, config.endpoint, connected]);

    const newTab = <span className="screen-reader-text"> {s.opensInNewTab}</span>;

    // Header controls, lifted into the section's header bar next to its
    // collapse arrow (the section itself renders the "Languages" title).
    const header = (count: string | null) => (
        <div className="wp-panel-languages-table__head">
            {count && <span className="wp-panel-languages-table__count">{count}</span>}
            <button
                type="button"
                className="wp-panel-languages-table__icon-btn"
                onClick={refresh}
                disabled={loading}
                aria-label={s.refresh}
                title={s.refresh}
            >
                <RefreshIcon className={loading ? 'wp-panel-languages-table__spin' : undefined}/>
            </button>
            <a
                className="wp-panel-languages-table__btn wp-panel-languages-table__btn--outline"
                href={dashboardUrl}
                target="_blank"
                rel="noopener noreferrer"
            >
                {s.manage}
                <ExternalIcon/>
                {newTab}
            </a>
        </div>
    );

    // One-click suggestions. Already-added languages are filtered out (matched
    // by variant or base lang code); adding one drops it from the list (it's
    // now in `existing`) so the next popular language takes its place. While
    // the empty state's "Added" flash runs, `data` is still the old list, so
    // the added tile stays in place.
    const existing = new Set<string>();
    (data?.languages ?? []).forEach((l) => {
        if (l.variant) existing.add(l.variant);
        if (l.lang) existing.add(l.lang);
    });
    const available = POPULAR_LANGUAGES.filter((p) => !existing.has(p.variant));
    const isBusy = addingVariant !== null || justAdded !== null;
    const nameOf = (variant: string) => names[variant] ?? variant;
    const tagOf = (p: PopularLanguage) => (p.tone === 'rose' ? s.tagHighPurchasingPower : s.tagFastGrowingMarket);

    const errorNote = addError && (
        <p className="wp-panel-languages-table__add-error" role="alert">{addError}</p>
    );

    const upsellModal = planLimitOpen && (
        <Modal
            onRequestClose={() => setPlanLimitOpen(false)}
            className="wp-panel-languages-table__upsell-modal"
            size="medium"
            contentLabel={s.upgradeLabel}
            __experimentalHideHeader
        >
            <div className="wp-panel-languages-table__upsell">
                <button
                    type="button"
                    className="wp-panel-languages-table__upsell-close"
                    aria-label={s.close}
                    onClick={() => setPlanLimitOpen(false)}
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>

                <div className="wp-panel-languages-table__upsell-body">
                    <span className="wp-panel-languages-table__upsell-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                    </span>
                    <h2 className="wp-panel-languages-table__upsell-title">{s.upgradeTitle}</h2>
                    <p className="wp-panel-languages-table__upsell-desc">{s.upgradeText}</p>
                    <a
                        className="wp-panel-languages-table__upsell-cta"
                        href={upgradeUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        onClick={() => setPlanLimitOpen(false)}
                    >
                        {s.upgradeCta}
                        {newTab}
                    </a>
                </div>

                <div className="wp-panel-languages-table__upsell-bonus">
                    <span className="wp-panel-languages-table__upsell-bonus-check" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                            <polyline points="22 4 12 14.01 9 11.01"/>
                        </svg>
                    </span>
                    <p>{s.upgradeBonus}</p>
                </div>

                <div className="wp-panel-languages-table__upsell-foot">
                    <button
                        type="button"
                        className="wp-panel-languages-table__upsell-later"
                        onClick={() => setPlanLimitOpen(false)}
                    >
                        {s.maybeLater}
                    </button>
                </div>
            </div>
        </Modal>
    );

    // Disconnected: `data` was dropped and the fetch is gated, so the ordinary
    // empty state below renders at once — the same screen Refresh used to
    // reach — never the old project's rows.
    if (connected && loading && !data) {
        return (
            <div className="wp-panel-languages-table">
                <div className="wp-panel-languages-table__loading">{s.loading}</div>
            </div>
        );
    }

    if (connected && error) {
        return (
            <div className="wp-panel-languages-table">
                {header(null)}
                <div className="wp-panel-languages-table__error">{error}</div>
            </div>
        );
    }

    // Empty: no target languages yet (the source language alone doesn't count).
    // One task — pick the first language — with the tiles as the call to action.
    if (isEmpty) {
        const source = data?.languages.find((l) => l.isSource);
        const sourceName = source ? source.name || source.originalName : '';
        const tiles = available.slice(0, MAX_SUGGESTIONS);
        // "{name}" is bolded inside the translated "Your site is in %s".
        const [sourceBefore, sourceAfter = ''] = (s.sourceIs ?? '%s').split('%s');

        return (
            <div className="wp-panel-languages-table" id={fieldId}>
                {header(justAdded ? fmt(s.addedOne, 1) : s.countNone ?? null)}
                <div className="wp-panel-languages-table__empty">
                    <div className="wp-panel-languages-table__empty-inner">
                        <div className="wp-panel-languages-table__intro">
                            {sourceName && (
                                <span className="wp-panel-languages-table__source">
                                    {source?.flagUrl && (
                                        <img src={source.flagUrl} alt="" className="wp-panel-languages-table__source-flag"/>
                                    )}
                                    <span>
                                        {sourceBefore}<b>{sourceName}</b>{sourceAfter}
                                    </span>
                                </span>
                            )}
                            <h3 className="wp-panel-languages-table__intro-title">{s.emptyTitle}</h3>
                            <p className="wp-panel-languages-table__intro-text">{s.emptyText}</p>
                            <a
                                className="wp-panel-languages-table__browse"
                                href={dashboardUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {s.browseAll}
                                <ArrowIcon/>
                                {newTab}
                            </a>
                        </div>

                        <div className="wp-panel-languages-table__tiles-wrap">
                            {tiles.length > 0 && (
                                <div className="wp-panel-languages-table__tiles">
                                    {tiles.map((p) => {
                                        const name = nameOf(p.variant);
                                        const adding = addingVariant === p.variant;
                                        const added = justAdded === p.variant;
                                        return (
                                            <div
                                                key={p.variant}
                                                className={`wp-panel-languages-table__tile${added ? ' is-added' : ''}`}
                                            >
                                                <img src={`${FLAG_CDN}/${p.cc}.svg`} alt="" className="wp-panel-languages-table__flag"/>
                                                <div className="wp-panel-languages-table__tile-info">
                                                    <span className="wp-panel-languages-table__tile-name">{name}</span>
                                                    <span className="wp-panel-languages-table__tile-meta">
                                                        {formatSpeakers(p.speakers, s)}
                                                        <span className={`wp-panel-languages-table__tag wp-panel-languages-table__tag--${p.tone}`}>
                                                            {tagOf(p)}
                                                        </span>
                                                    </span>
                                                </div>
                                                {added ? (
                                                    <span className="wp-panel-languages-table__added" role="status">
                                                        <CheckIcon/>
                                                        {s.added}
                                                    </span>
                                                ) : (
                                                    <button
                                                        type="button"
                                                        className="wp-panel-languages-table__add"
                                                        onClick={() => addLanguage(p.variant)}
                                                        disabled={isBusy}
                                                        aria-label={adding ? s.adding : fmt(s.addLanguage, name)}
                                                    >
                                                        {adding ? <SpinnerIcon/> : <PlusIcon/>}
                                                        {adding ? s.adding : s.add}
                                                    </button>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                            {errorNote}
                        </div>
                    </div>
                </div>
                {upsellModal}
            </div>
        );
    }

    // `data` is non-null here: an empty list returned above.
    const languages = data?.languages ?? [];
    // Same meaning as the old "Live in N languages" line: every enabled
    // language, the source included.
    const liveCount = languages.filter((l) => !l.isDisabled).length;
    const pills = available.slice(0, MAX_PILLS);

    return (
        <div className="wp-panel-languages-table" id={fieldId}>
            {header(fmt(liveCount === 1 ? s.liveOne : s.liveMany, liveCount))}
            <div className="wp-panel-languages-table__table-wrap">
                <table className="wp-panel-languages-table__table">
                    <thead>
                    <tr>
                        <th>{s.colLanguage}</th>
                        <th>{s.colUrl}</th>
                        <th>{s.colStatus}</th>
                    </tr>
                    </thead>
                    <tbody>
                    {languages.map((lang) => {
                        const rowKey = lang.region || lang.urlPrefix || lang.lang;
                        const url = buildLangUrl(lang.urlPrefix);
                        const copied = copiedKey === rowKey;
                        return (
                            <tr key={rowKey} className={lang.isDisabled ? 'wp-panel-languages-table__row--disabled' : ''}>
                                <td>
                                    <div className="wp-panel-languages-table__lang-cell">
                                        {lang.flagUrl && (
                                            <img
                                                src={lang.flagUrl}
                                                alt=""
                                                className="wp-panel-languages-table__flag"
                                            />
                                        )}
                                        <span className="wp-panel-languages-table__lang-meta">
                                            <span className="wp-panel-languages-table__name-row">
                                                <span className="wp-panel-languages-table__name">{lang.originalName || lang.variant}</span>
                                                {lang.isSource && <span className="wp-panel-languages-table__is-source">{s.source}</span>}
                                            </span>
                                            {lang.region && <span className="wp-panel-languages-table__locale">{lang.region}</span>}
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div className="wp-panel-languages-table__url-cell">
                                        <a
                                            className="wp-panel-languages-table__url"
                                            href={url.full}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            {url.display}
                                        </a>
                                        <button
                                            type="button"
                                            className="wp-panel-languages-table__copy"
                                            onClick={() => copyUrl(rowKey, url.full)}
                                            aria-label={copied ? s.copied : s.copyUrl}
                                            title={copied ? s.copied : s.copyUrl}
                                        >
                                            {copied ? (
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                                    <polyline points="20 6 9 17 4 12"/>
                                                </svg>
                                            ) : (
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                                                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                                                </svg>
                                            )}
                                        </button>
                                    </div>
                                </td>
                                <td>
                                    <span
                                        className={`wp-panel-languages-table__status ${lang.isDisabled ? 'wp-panel-languages-table__status--disabled' : 'wp-panel-languages-table__status--active'}`}>
                                        <span className="wp-panel-languages-table__status-dot" aria-hidden="true"/>
                                        {lang.isDisabled ? s.statusDisabled : s.statusLive}
                                    </span>
                                </td>
                            </tr>
                        );
                    })}
                    </tbody>
                </table>
            </div>

            {pills.length > 0 && (
                <div className="wp-panel-languages-table__suggest">
                    <span className="wp-panel-languages-table__suggest-label">
                        {s.suggestedTitle}
                        <span>{s.suggestedText}</span>
                    </span>
                    <div className="wp-panel-languages-table__pills">
                        {pills.map((p) => {
                            const name = nameOf(p.variant);
                            const adding = addingVariant === p.variant;
                            return (
                                <button
                                    key={p.variant}
                                    type="button"
                                    className="wp-panel-languages-table__pill"
                                    onClick={() => addLanguage(p.variant)}
                                    disabled={isBusy}
                                    aria-label={adding ? s.adding : fmt(s.addLanguage, name)}
                                >
                                    <img src={`${FLAG_CDN}/${p.cc}.svg`} alt="" className="wp-panel-languages-table__pill-flag"/>
                                    {name}
                                    {adding ? <SpinnerIcon/> : <PlusIcon/>}
                                </button>
                            );
                        })}
                    </div>
                    <a
                        className="wp-panel-languages-table__browse wp-panel-languages-table__browse--end"
                        href={dashboardUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        {s.allLanguages}
                        <ArrowIcon/>
                        {newTab}
                    </a>
                    {errorNote}
                </div>
            )}
            {upsellModal}
        </div>
    );
}
