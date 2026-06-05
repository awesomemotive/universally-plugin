import {useState, useEffect} from 'react';
import {useFieldApi} from './useFieldApi';

interface FieldConfig {
    endpoint: string;
    /** Universally app base URL (honors the wp-config override). */
    appUrl?: string;
    /** Connected project id; enables a deep-link to its language panel. */
    projectId?: string;

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

interface Suggestion {
    lang: string;
    name: string;
    flag: string;
    speakers: string;
    tag: string;
    tone: 'rose' | 'blue';
}

// Curated high-reach languages surfaced as an upsell to add more. Numbers are
// approximate total-speaker counts; tags mirror the hosted onboarding's framing.
const SUGGESTIONS: Suggestion[] = [
    {lang: 'es', name: 'Spanish', flag: '🇪🇸', speakers: '559M speakers', tag: 'High Purchasing Power', tone: 'rose'},
    {lang: 'fr', name: 'French', flag: '🇫🇷', speakers: '310M speakers', tag: 'Fast Growing Market', tone: 'blue'},
    {lang: 'de', name: 'German', flag: '🇩🇪', speakers: '135M speakers', tag: 'High Purchasing Power', tone: 'rose'},
];

export function LanguagesTableField({fieldId, config}: Props) {
    const cached = cache.get(config.endpoint);
    const [data, setData] = useState<LanguagesResponse | null>(cached ?? null);
    const {loading, error, request} = useFieldApi<LanguagesResponse>(config.endpoint);

    // Languages are managed in the app dashboard. When the connected project id
    // is known, deep-link straight to its language panel; otherwise fall back to
    // the dashboard root (which lands the user on their project).
    const appUrl = config.appUrl || 'https://app.universally.com';
    const dashboardUrl = config.projectId
        ? `${appUrl}/projects/${config.projectId}/languages`
        : appUrl;

    const refresh = async () => {
        cache.delete(config.endpoint);
        const res = await request('GET');
        if (res) {
            cache.set(config.endpoint, res);
            setData(res);
        }
    };

    useEffect(() => {
        if (cache.has(config.endpoint)) return;
        const fetch = async () => {
            const res = await request('GET');
            if (res) {
                cache.set(config.endpoint, res);
                setData(res);
            }
        };
        fetch();
    }, [request, config.endpoint]);

    const actions = (
        <div className="wp-panel-languages-table__actions">
            <button
                type="button"
                className="wp-panel-languages-table__btn wp-panel-languages-table__btn--refresh"
                onClick={refresh}
                disabled={loading}
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={loading ? 'wp-panel-languages-table__refresh-icon--spinning' : ''}>
                    <polyline points="23 4 23 10 17 10"/>
                    <polyline points="1 20 1 14 7 14"/>
                    <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                </svg>
                {loading ? 'Refreshing…' : 'Refresh'}
            </button>
            <a
                className="wp-panel-languages-table__btn wp-panel-languages-table__btn--add"
                href={dashboardUrl}
                target="_blank"
                rel="noopener noreferrer"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Add Languages
            </a>
        </div>
    );

    // Upsell CTA shown under the table (and in the empty state): frames the reach
    // opportunity and surfaces a few high-value languages to add, each linking to
    // the dashboard's language panel. Already-added languages are filtered out.
    const existingLangs = new Set((data?.languages ?? []).map((l) => l.lang).filter(Boolean));
    const suggestions = SUGGESTIONS.filter((s) => !existingLangs.has(s.lang));

    const addLanguageCta = (
        <div className="wp-panel-languages-table__cta">
            <div className="wp-panel-languages-table__cta-head">
                <p className="wp-panel-languages-table__cta-title">Reach more of your global audience</p>
                <p className="wp-panel-languages-table__cta-desc">
                    Every language you add opens your site to a new market. Add more to grow your reach.
                </p>
            </div>
            {suggestions.length > 0 && (
                <div className="wp-panel-languages-table__cta-list">
                    {suggestions.map((s) => (
                        <div key={s.lang} className="wp-panel-languages-table__cta-row">
                            <span className="wp-panel-languages-table__cta-flag">{s.flag}</span>
                            <div className="wp-panel-languages-table__cta-info">
                                <span className="wp-panel-languages-table__cta-name">
                                    {s.name}
                                    <span className={`wp-panel-languages-table__cta-tag wp-panel-languages-table__cta-tag--${s.tone}`}>
                                        {s.tag}
                                    </span>
                                </span>
                                <span className="wp-panel-languages-table__cta-speakers">{s.speakers}</span>
                            </div>
                            <a
                                className="wp-panel-languages-table__btn wp-panel-languages-table__btn--add"
                                href={dashboardUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <line x1="12" y1="5" x2="12" y2="19"/>
                                    <line x1="5" y1="12" x2="19" y2="12"/>
                                </svg>
                                Add
                            </a>
                        </div>
                    ))}
                </div>
            )}
            <a
                className="wp-panel-languages-table__cta-all"
                href={dashboardUrl}
                target="_blank"
                rel="noopener noreferrer"
            >
                Browse all languages &rarr;
            </a>
        </div>
    );

    if (loading && !data) {
        return (
            <div className="wp-panel-languages-table">
                <div className="wp-panel-languages-table__loading">Loading languages...</div>
            </div>
        );
    }

    if (error) {
        return (
            <div className="wp-panel-languages-table">
                {actions}
                <div className="wp-panel-languages-table__error">{error}</div>
            </div>
        );
    }

    if (!data || data.languages.length === 0) {
        return (
            <div className="wp-panel-languages-table">
                {actions}
                <div className="wp-panel-languages-table__empty">
                    <svg className="wp-panel-languages-table__empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="2" y1="12" x2="22" y2="12"/>
                        <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                    </svg>
                    <p className="wp-panel-languages-table__empty-title">No target languages configured</p>
                    <p className="wp-panel-languages-table__empty-desc">Add languages from the dashboard to start translating your site.</p>
                    {addLanguageCta}
                </div>
            </div>
        );
    }

    return (
        <div className="wp-panel-languages-table" id={fieldId}>
            {actions}
            <table className="wp-panel-languages-table__table">
                <thead>
                <tr>
                    <th>Language</th>
                    <th>Region</th>
                    <th>URL Prefix</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                {data.languages.map((lang) => (
                    <tr key={lang.region} className={lang.isDisabled ? 'wp-panel-languages-table__row--disabled' : ''}>
                        <td className="wp-panel-languages-table__lang-cell">
                            {lang.flagUrl && (
                                <img
                                    src={lang.flagUrl}
                                    alt=""
                                    className="wp-panel-languages-table__flag"
                                />
                            )}
                            <a href={`/${lang.urlPrefix}/`} target="_blank" className="wp-panel-languages-table__name">{lang.originalName || lang.variant}</a>
                            {lang.isSource && <span className="wp-panel-languages-table__is-source">Source</span>}
                        </td>
                        <td>{lang.region}</td>
                        <td><code className="wp-panel-languages-table__code">/{lang.urlPrefix}</code></td>
                        <td>
                <span
                    className={`wp-panel-languages-table__status ${lang.isDisabled ? 'wp-panel-languages-table__status--disabled' : 'wp-panel-languages-table__status--active'}`}>
                  {lang.isDisabled ? 'Disabled' : 'Active'}
                </span>
                        </td>
                    </tr>
                ))}
                </tbody>
            </table>
            {addLanguageCta}
        </div>
    );
}
