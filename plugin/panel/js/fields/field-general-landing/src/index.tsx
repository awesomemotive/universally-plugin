import { useEffect, useRef, useState } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { ApiKeyField } from '../../field-api-key/src';
import { formatInline } from '../../../utils/formatInline';

interface LandingLanguage {
  /** Flag file name inside `assetsUrl` (e.g. "es.svg"). */
  flag: string;
  name: string;
  tag: string;
  tone: 'rose' | 'blue';
  users: string;
}

interface LandingStep {
  title: string;
  description: string;
}

interface LandingStrings {
  heroEyebrow?: string;
  heroTitleAioseo?: string;
  heroTitle?: string;
  heroIntroAioseo?: string;
  heroIntro?: string;
  heroBody?: string;
  launchWizard?: string;
  readGuide?: string;
  dismiss?: string;
  opensInNewTab?: string;
  audienceTitle?: string;
  audienceSubtitle?: string;
  add?: string;
  browseLanguages?: string;
  stepsTitle?: string;
  stepsSubtitle?: string;
  getStarted?: string;
  ctaTitle?: string;
  ctaSubtitle?: string;
  manualLabel?: string;
  // "Check again" (reconnect + banner modes)
  checkAgain?: string;
  checking?: string;
  checkedJustNow?: string;
  checkFailed?: string;
  recovered?: string;
  // Reconnect mode
  alertPill?: string;
  reconnectTitle?: string;
  reconnectText?: string;
  reconnect?: string;
  newKeyLabel?: string;
  removeKey?: string;
  removeConfirm?: string;
  removeCancel?: string;
  removing?: string;
  removeFailed?: string;
  languagesTitle?: string;
  languagesLocked?: string;
  // Banner mode
  bannerTitle?: string;
  bannerText?: string;
  bannerTextJustNow?: string;
  bannerRecovered?: string;
}

interface FieldConfig {
  /**
   * landing (default): not connected yet. reconnect: the stored key was
   * rejected. banner: the key couldn't be checked (shown above the normal tab).
   */
  mode?: 'landing' | 'reconnect' | 'banner';
  /** Reconnect mode: short masked stored key. */
  maskedKey?: string;
  /** Reconnect mode: why the API rejected the key. */
  statusMessage?: string;
  /** Reconnect mode: "checked 4 mins ago", formatted server-side. */
  checkedLabel?: string;
  /** Reconnect mode: key comes from the UNIVERSALLY_API_KEY constant (can't be replaced or removed here). */
  keyLocked?: boolean;
  /** Hosted onboarding URL (built server-side with a fresh state). */
  connectUrl?: string;
  docsUrl?: string;
  /** Base URL of plugin/assets/general/, with trailing slash. */
  assetsUrl?: string;
  /** Looping, muted hero video (CDN). */
  heroVideoUrl?: string;
  /** Installed by All in One SEO — swaps in the AIOSEO hero copy. */
  isAioseo?: boolean;
  /** Current user already closed the hero card. */
  heroDismissed?: boolean;
  /** REST path that persists the hero dismissal for the current user. */
  dismissEndpoint?: string;
  /** api-key field endpoint, reused for manual key entry. */
  apiKeyEndpoint?: string;
  apiKeyPlaceholder?: string;
  strings?: LandingStrings;
  languages?: LandingLanguage[];
  steps?: LandingStep[];
  [key: string]: unknown;
}

interface Props {
  fieldId: string;
  config: FieldConfig;
  value: unknown;
  onChange: (value: unknown) => void;
}

const BLOCK = 'wp-panel-general-landing';

function ArrowRightIcon() {
  return (
    <svg className={`${BLOCK}__button-icon`} width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false">
      <path d="M8 2.667 6.94 3.727l3.523 3.523H2.667v1.5h7.796L6.94 12.273 8 13.333 13.333 8 8 2.667Z" fill="currentColor" />
    </svg>
  );
}

function ExternalIcon() {
  return (
    <svg className={`${BLOCK}__link-icon`} width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true" focusable="false">
      <path d="M3.5 10.5 10.5 3.5M5 3.5h5.5V9" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

/**
 * Image in a fixed-ratio box. If the file is missing the box keeps its size
 * (and its placeholder background) instead of showing a broken-image icon.
 */
function Illustration({ src, className }: { src: string; className: string }) {
  const [failed, setFailed] = useState(false);
  return (
    <div className={`${className}${failed ? ` ${className}--empty` : ''}`}>
      {!failed && <img src={src} alt="" onError={() => setFailed(true)} />}
    </div>
  );
}

interface KeyStatusResponse {
  valid: boolean;
  message: string;
  status?: 'none' | 'valid' | 'rejected' | 'unknown';
  code?: string;
  checked_at?: number;
}

/** Reload shortly after a success message, so it can be read/announced. */
function reloadSoon(delay = 1200): void {
  window.setTimeout(() => window.location.reload(), delay);
}

/** Ask the API about the stored key now, skipping the server's 5-minute cache. */
function checkKeyNow(endpoint: string): Promise<KeyStatusResponse> {
  const sep = endpoint.includes('?') ? '&' : '?';
  return apiFetch<KeyStatusResponse>({ path: `${endpoint}${sep}fresh=1`, method: 'GET' });
}

/** Drop the trailing period so the message reads well in the "·" list. */
function trimPeriod(text: string): string {
  return text.replace(/\.\s*$/, '');
}

/**
 * Polite screen-reader announcer. Clears the region first so repeating the
 * same text (a second "still rejected") is announced again.
 */
function useAnnouncer(): [string, (text: string) => void] {
  const [text, setText] = useState('');
  const timer = useRef<number>();
  useEffect(() => () => window.clearTimeout(timer.current), []);
  const announce = (next: string) => {
    setText('');
    window.clearTimeout(timer.current);
    timer.current = window.setTimeout(() => setText(next), 100);
  };
  return [text, announce];
}

function RefreshIcon({ spinning }: { spinning: boolean }) {
  return (
    <svg
      className={`${BLOCK}__button-icon${spinning ? ` ${BLOCK}__spin` : ''}`}
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      <path d="M21 12a9 9 0 0 1-15.5 6.2L3 16" />
      <path d="M3 12a9 9 0 0 1 15.5-6.2L21 8" />
      <path d="M21 3v5h-5" />
      <path d="M3 21v-5h5" />
    </svg>
  );
}

function CheckIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
      <path d="M20 6 9 17l-5-5" />
    </svg>
  );
}

function WarningIcon() {
  return (
    <svg className={`${BLOCK}__banner-icon`} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
      <path d="M12 9v4" />
      <path d="M12 17h.01" />
      <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" />
    </svg>
  );
}

function GlobeIcon() {
  return (
    <svg className={`${BLOCK}__locked-icon`} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
      <circle cx="12" cy="12" r="10" />
      <path d="M2 12h20" />
      <path d="M12 2a15.3 15.3 0 0 1 0 20 15.3 15.3 0 0 1 0-20z" />
    </svg>
  );
}

/**
 * "Check again" button. Uses aria-disabled while checking (not `disabled`) so
 * keyboard focus stays on it and the result is announced from the same spot.
 */
function CheckAgainButton({
  checking,
  strings,
  className,
  onClick,
}: {
  checking: boolean;
  strings: LandingStrings;
  className: string;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      className={className}
      aria-disabled={checking || undefined}
      onClick={() => {
        if (!checking) onClick();
      }}
    >
      <RefreshIcon spinning={checking} />
      {checking ? strings.checking : strings.checkAgain}
    </button>
  );
}

function HeroVideo({ src }: { src?: string }) {
  return (
    <div className={`${BLOCK}__hero-media`}>
      {src && (
        // Decorative loop: muted + playsInline so browsers allow autoplay.
        <video
          src={src}
          autoPlay
          loop
          muted
          playsInline
          preload="auto"
          disablePictureInPicture
          aria-hidden="true"
          tabIndex={-1}
        />
      )}
    </div>
  );
}

/**
 * Reconnect mode: the stored key was rejected by the API. One hero card
 * (Reconnect + Check again, the key in a quiet line, rare actions at the
 * bottom) and a placeholder Languages card.
 */
function ReconnectView({ config }: { config: FieldConfig }) {
  const s = config.strings ?? {};
  const endpoint = config.apiKeyEndpoint ?? '';

  const [checking, setChecking] = useState(false);
  const [recovered, setRecovered] = useState(false);
  const [message, setMessage] = useState(config.statusMessage ?? '');
  const [checkedLabel, setCheckedLabel] = useState(config.checkedLabel ?? '');
  const [error, setError] = useState('');
  const [showManual, setShowManual] = useState(false);
  const [confirmingRemove, setConfirmingRemove] = useState(false);
  const [removing, setRemoving] = useState(false);
  const [announcement, announce] = useAnnouncer();

  const removeRef = useRef<HTMLButtonElement>(null);
  const cancelRef = useRef<HTMLButtonElement>(null);
  const confirmStarted = useRef(false);

  // Revealing the key input moves focus into it (its toggle disappears).
  useEffect(() => {
    if (showManual) document.getElementById('general_landing_api_key')?.focus();
  }, [showManual]);

  // Move focus into the confirm step, and back to "Remove saved key" on cancel.
  useEffect(() => {
    if (confirmingRemove) {
      confirmStarted.current = true;
      cancelRef.current?.focus();
    } else if (confirmStarted.current) {
      removeRef.current?.focus();
    }
  }, [confirmingRemove]);

  const checkAgain = async () => {
    setChecking(true);
    setError('');
    try {
      const res = await checkKeyNow(endpoint);
      if (res.valid) {
        setRecovered(true);
        reloadSoon();
        return;
      }
      setMessage(res.message || message);
      setCheckedLabel(s.checkedJustNow ?? '');
      announce(`${trimPeriod(res.message || message)} · ${s.checkedJustNow ?? ''}`);
    } catch {
      setError(s.checkFailed ?? '');
      announce(s.checkFailed ?? '');
    } finally {
      setChecking(false);
    }
  };

  const removeKey = async () => {
    setRemoving(true);
    setError('');
    try {
      await apiFetch({ path: endpoint, method: 'POST', data: { value: '', action: 'deactivate' } });
      // Reload into the not-connected landing.
      window.location.reload();
    } catch {
      setRemoving(false);
      setError(s.removeFailed ?? '');
      announce(s.removeFailed ?? '');
    }
  };

  return (
    <div className={`${BLOCK} ${BLOCK}--reconnect`}>
      {recovered && (
        <div className={`${BLOCK}__toast`} role="status">
          <CheckIcon />
          {s.recovered}
        </div>
      )}

      <section className={`${BLOCK}__card ${BLOCK}__hero ${BLOCK}__hero--reconnect`}>
        <div className={`${BLOCK}__hero-content`}>
          <span className={`${BLOCK}__alert-pill`}>
            <span className={`${BLOCK}__alert-dot`} aria-hidden="true" />
            {s.alertPill}
          </span>
          <h2 className={`${BLOCK}__hero-title`}>{s.reconnectTitle}</h2>
          <p className={`${BLOCK}__text ${BLOCK}__hero-text`}>{s.reconnectText}</p>

          <div className={`${BLOCK}__hero-actions ${BLOCK}__hero-actions--tight`}>
            {config.connectUrl && (
              <a className={`${BLOCK}__button`} href={config.connectUrl}>
                {s.reconnect}
                <ArrowRightIcon />
              </a>
            )}
            {endpoint && (
              <CheckAgainButton
                checking={checking}
                strings={s}
                className={`${BLOCK}__button ${BLOCK}__button--ghost`}
                onClick={checkAgain}
              />
            )}
          </div>

          <p className={`${BLOCK}__keyline`}>
            {config.maskedKey && (
              <>
                <span className={`${BLOCK}__keyline-key`}>{config.maskedKey}</span>
                <span className={`${BLOCK}__keyline-sep`} aria-hidden="true">·</span>
              </>
            )}
            {message && (
              <>
                <span className={`${BLOCK}__keyline-bad`}>{trimPeriod(message)}</span>
                <span className={`${BLOCK}__keyline-sep`} aria-hidden="true">·</span>
              </>
            )}
            <span>{checkedLabel}</span>
          </p>

          {error && (
            <p className={`${BLOCK}__error`} role="alert">
              {error}
            </p>
          )}

          {!config.keyLocked && endpoint && (
            <>
              <div className={`${BLOCK}__hero-foot`}>
                {!showManual && (
                  <button type="button" className={`${BLOCK}__text-button`} onClick={() => setShowManual(true)}>
                    {s.newKeyLabel}
                  </button>
                )}
                {confirmingRemove ? (
                  <span className={`${BLOCK}__confirm`}>
                    <button
                      type="button"
                      className={`${BLOCK}__text-button ${BLOCK}__text-button--danger`}
                      aria-disabled={removing || undefined}
                      onClick={() => {
                        if (!removing) removeKey();
                      }}
                    >
                      {removing ? s.removing : s.removeConfirm}
                    </button>
                    <button
                      ref={cancelRef}
                      type="button"
                      className={`${BLOCK}__text-button ${BLOCK}__text-button--quiet`}
                      onClick={() => setConfirmingRemove(false)}
                      disabled={removing}
                    >
                      {s.removeCancel}
                    </button>
                  </span>
                ) : (
                  <button
                    ref={removeRef}
                    type="button"
                    className={`${BLOCK}__text-button ${BLOCK}__text-button--quiet`}
                    onClick={() => setConfirmingRemove(true)}
                  >
                    {s.removeKey}
                  </button>
                )}
              </div>

              {showManual && (
                // wp-panel-field__control pulls in the panel's standard input styles.
                <div className={`${BLOCK}__manual ${BLOCK}__manual--inline wp-panel-field__control wp-panel-field__control--full`}>
                  <ApiKeyField
                    fieldId="general_landing_api_key"
                    config={{ endpoint, placeholder: config.apiKeyPlaceholder }}
                    value=""
                    onChange={() => {}}
                    // Blank input: don't pre-fill the rejected key.
                    fetchOnMount={false}
                    // Reload into the connected General tab.
                    onActivated={() => reloadSoon(800)}
                  />
                </div>
              )}
            </>
          )}
        </div>

        <HeroVideo src={config.heroVideoUrl} />
      </section>

      <section className={`${BLOCK}__card`}>
        <header className={`${BLOCK}__card-header ${BLOCK}__card-header--compact`}>
          <h3 className={`${BLOCK}__card-title`}>{s.languagesTitle}</h3>
        </header>
        <div className={`${BLOCK}__locked`}>
          <GlobeIcon />
          <span>{s.languagesLocked}</span>
        </div>
      </section>

      <div className="screen-reader-text" aria-live="polite" aria-atomic="true">
        {announcement}
      </div>
    </div>
  );
}

/**
 * Banner mode: the API couldn't be reached to check the key. Sits above the
 * normal connected tab; "Check again" re-checks and hides it once it works.
 */
function BannerView({ config }: { config: FieldConfig }) {
  const s = config.strings ?? {};
  const endpoint = config.apiKeyEndpoint ?? '';

  const [checking, setChecking] = useState(false);
  const [hidden, setHidden] = useState(false);
  const [text, setText] = useState(s.bannerText ?? '');
  const [announcement, announce] = useAnnouncer();

  const checkAgain = async () => {
    setChecking(true);
    try {
      const res = await checkKeyNow(endpoint);
      if (res.valid) {
        setHidden(true);
        announce(s.bannerRecovered ?? '');
        return;
      }
      if (res.status === 'rejected' || res.status === 'none') {
        // The page now needs the reconnect (or landing) screen.
        window.location.reload();
        return;
      }
      setText(s.bannerTextJustNow ?? '');
      announce(`${s.bannerTitle ?? ''}. ${s.bannerTextJustNow ?? ''}`);
    } catch {
      setText(s.checkFailed ?? '');
      announce(s.checkFailed ?? '');
    } finally {
      setChecking(false);
    }
  };

  return (
    <div className={`${BLOCK} ${BLOCK}--banner`}>
      {!hidden && (
        <div className={`${BLOCK}__banner`}>
          <div className={`${BLOCK}__banner-text`}>
            <WarningIcon />
            <div>
              <strong className={`${BLOCK}__banner-title`}>{s.bannerTitle}</strong>
              <span className={`${BLOCK}__banner-desc`}>{text}</span>
            </div>
          </div>
          {endpoint && (
            <CheckAgainButton
              checking={checking}
              strings={s}
              className={`${BLOCK}__button ${BLOCK}__button--secondary`}
              onClick={checkAgain}
            />
          )}
        </div>
      )}
      <div className="screen-reader-text" aria-live="polite" aria-atomic="true">
        {announcement}
      </div>
    </div>
  );
}

export function GeneralLandingField({ config }: Props) {
  if (config.mode === 'reconnect') return <ReconnectView config={config} />;
  if (config.mode === 'banner') return <BannerView config={config} />;
  return <LandingView config={config} />;
}

/** Not connected yet: hero, language suggestions, steps and closing CTA. */
function LandingView({ config }: { config: FieldConfig }) {
  const s = config.strings ?? {};
  const assets = config.assetsUrl ?? '';
  const connectUrl = config.connectUrl || undefined;
  const isAioseo = !!config.isAioseo;

  const [heroHidden, setHeroHidden] = useState(!!config.heroDismissed);
  const [showManual, setShowManual] = useState(false);

  const dismissHero = () => {
    // Optimistic: hide now, persist in the background. A failed request only
    // means the hero comes back on the next load.
    setHeroHidden(true);
    if (config.dismissEndpoint) {
      apiFetch({ path: config.dismissEndpoint, method: 'POST' }).catch(() => {});
    }
  };

  const launchButton = (
    <a className={`${BLOCK}__button`} href={connectUrl}>
      {s.launchWizard}
      <ArrowRightIcon />
    </a>
  );

  const footerLink = (label?: string) => (
    <div className={`${BLOCK}__card-footer`}>
      <a className={`${BLOCK}__footer-link`} href={connectUrl}>
        {label}
        <img src={`${assets}east.svg`} width={20} height={20} alt="" aria-hidden="true" />
      </a>
    </div>
  );

  return (
    <div className={BLOCK}>
      {!heroHidden && (
        <section className={`${BLOCK}__card ${BLOCK}__hero`}>
          <button
            type="button"
            className={`${BLOCK}__dismiss`}
            onClick={dismissHero}
            aria-label={s.dismiss}
            title={s.dismiss}
          >
            <span className="dashicons dashicons-no-alt" aria-hidden="true" />
          </button>

          <div className={`${BLOCK}__hero-content`}>
            {isAioseo && s.heroEyebrow && <p className={`${BLOCK}__eyebrow`}>{s.heroEyebrow}</p>}
            <h2 className={`${BLOCK}__hero-title`}>{isAioseo ? s.heroTitleAioseo : s.heroTitle}</h2>
            <p className={`${BLOCK}__text`}>{formatInline((isAioseo ? s.heroIntroAioseo : s.heroIntro) ?? '')}</p>
            {s.heroBody && <p className={`${BLOCK}__text`}>{s.heroBody}</p>}
            <div className={`${BLOCK}__hero-actions`}>
              {launchButton}
              {config.docsUrl && (
                <a className={`${BLOCK}__link`} href={config.docsUrl} target="_blank" rel="noopener noreferrer">
                  {s.readGuide}
                  <ExternalIcon />
                  {s.opensInNewTab && <span className="screen-reader-text">{s.opensInNewTab}</span>}
                </a>
              )}
            </div>
          </div>

          <div className={`${BLOCK}__hero-media`}>
            {config.heroVideoUrl && (
              // Decorative loop: muted + playsInline so browsers allow autoplay.
              <video
                src={config.heroVideoUrl}
                autoPlay
                loop
                muted
                playsInline
                preload="auto"
                disablePictureInPicture
                aria-hidden="true"
                tabIndex={-1}
              />
            )}
          </div>
        </section>
      )}

      <div className={`${BLOCK}__grid`}>
        <section className={`${BLOCK}__card ${BLOCK}__panel`}>
          <header className={`${BLOCK}__card-header`}>
            <h3 className={`${BLOCK}__card-title`}>{s.audienceTitle}</h3>
            <p className={`${BLOCK}__card-subtitle`}>{s.audienceSubtitle}</p>
          </header>
          <ul className={`${BLOCK}__languages`}>
            {(config.languages ?? []).map((lang) => (
              <li key={lang.flag} className={`${BLOCK}__language`}>
                <div className={`${BLOCK}__language-info`}>
                  <div className={`${BLOCK}__language-name-row`}>
                    <span className={`${BLOCK}__flag`}>
                      <img src={`${assets}${lang.flag}`} alt="" />
                    </span>
                    <span className={`${BLOCK}__language-name`}>{lang.name}</span>
                    <span className={`${BLOCK}__pill ${BLOCK}__pill--${lang.tone}`}>{lang.tag}</span>
                  </div>
                  <div className={`${BLOCK}__language-users`}>{lang.users}</div>
                </div>
                <a className={`${BLOCK}__add`} href={connectUrl}>
                  <img src={`${assets}plus-circle.svg`} width={14} height={14} alt="" aria-hidden="true" />
                  {s.add}
                  <span className="screen-reader-text"> {lang.name}</span>
                </a>
              </li>
            ))}
          </ul>
          {footerLink(s.browseLanguages)}
        </section>

        <section className={`${BLOCK}__card ${BLOCK}__panel`}>
          <header className={`${BLOCK}__card-header`}>
            <h3 className={`${BLOCK}__card-title`}>{s.stepsTitle}</h3>
            <p className={`${BLOCK}__card-subtitle`}>{s.stepsSubtitle}</p>
          </header>
          <ol className={`${BLOCK}__steps`}>
            {(config.steps ?? []).map((step, index) => (
              <li key={step.title} className={`${BLOCK}__step`}>
                <div className={`${BLOCK}__step-head`}>
                  <span className={`${BLOCK}__step-number`} aria-hidden="true">{index + 1}</span>
                  <span className={`${BLOCK}__step-title`}>{step.title}</span>
                </div>
                <p className={`${BLOCK}__step-desc`}>{step.description}</p>
              </li>
            ))}
          </ol>
          {footerLink(s.getStarted)}
        </section>
      </div>

      <section className={`${BLOCK}__card ${BLOCK}__cta`}>
        <Illustration src={`${assets}globe-illustration.png`} className={`${BLOCK}__cta-media`} />
        <h2 className={`${BLOCK}__cta-title`}>{s.ctaTitle}</h2>
        <p className={`${BLOCK}__cta-subtitle`}>{s.ctaSubtitle}</p>
        {launchButton}
        {config.apiKeyEndpoint &&
          (showManual ? (
            // wp-panel-field__control pulls in the panel's standard input styles.
            <div className={`${BLOCK}__manual wp-panel-field__control wp-panel-field__control--full`}>
              <ApiKeyField
                fieldId="general_landing_api_key"
                config={{ endpoint: config.apiKeyEndpoint, placeholder: config.apiKeyPlaceholder }}
                value=""
                onChange={() => {}}
                // Reload into the connected General tab (status + languages).
                onActivated={() => window.setTimeout(() => window.location.reload(), 800)}
              />
            </div>
          ) : (
            <button type="button" className={`${BLOCK}__manual-toggle`} onClick={() => setShowManual(true)}>
              {s.manualLabel}
            </button>
          ))}
      </section>
    </div>
  );
}
