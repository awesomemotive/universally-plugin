import { useState, useEffect } from 'react';
import { Modal, Button } from '@wordpress/components';
import { useFieldApi } from './useFieldApi';

interface FieldConfig {
  endpoint: string;
  placeholder?: string;
  independent?: boolean;
  /** Connect mode: render the hosted-onboarding launch button instead of a raw key input. */
  connect?: boolean;
  /** Hosted onboarding URL the "Connect" button links to (built server-side with a fresh state). */
  connectUrl?: string;
  connectLabel?: string;
  connectDescription?: string;
  connectedLabel?: string;
  manualLabel?: string;
  disconnectLabel?: string;
  /** Confirmation popup shown before disconnecting (connect mode). */
  disconnectConfirmTitle?: string;
  disconnectConfirmLabel?: string;
  disconnectConfirmButton?: string;
  disconnectCancelLabel?: string;
  /** Notice shown after disconnecting (connect mode). */
  disconnectedLabel?: string;
  /** Connected-state "API status" row labels. */
  statusLabel?: string;
  statusValue?: string;
  [key: string]: unknown;
}

interface Props {
  fieldId: string;
  config: FieldConfig;
  value: string;
  onChange: (value: string) => void;
}

interface ApiKeyResponse {
  valid: boolean;
  message: string;
  value?: string;
}

const cache = new Map<string, ApiKeyResponse>();

function maskKey(key: string): string {
  if (key.length <= 8) return '*'.repeat(key.length);
  return key.slice(0, 4) + '*'.repeat(key.length - 8) + key.slice(-4);
}

export function ApiKeyField({ fieldId, config }: Props) {
  const cached = cache.get(config.endpoint);
  const [inputValue, setInputValue] = useState(cached?.value ?? '');
  const [valid, setValid] = useState(cached?.valid ?? false);
  const [message, setMessage] = useState<string | null>(cached?.message || null);
  const [messageType, setMessageType] = useState<'success' | 'error' | 'info'>(
    cached ? (cached.valid ? 'success' : 'info') : 'info'
  );
  // Connect mode: the raw key input is hidden behind a "enter it manually" fallback.
  const [showManual, setShowManual] = useState(false);
  // Connect mode: require a confirm click before disconnecting (avoids accidental clicks).
  const [confirmingDisconnect, setConfirmingDisconnect] = useState(false);
  // Have we resolved the initial status yet? Avoids a Connect→Connected flash.
  const [resolved, setResolved] = useState(cache.has(config.endpoint));

  const { loading, error, request } = useFieldApi<ApiKeyResponse>(config.endpoint);

  useEffect(() => {
    if (cache.has(config.endpoint)) {
      setResolved(true);
      return;
    }
    const fetchStatus = async () => {
      const res = await request('GET');
      if (res) {
        cache.set(config.endpoint, res);
        setInputValue(res.value ?? '');
        setValid(res.valid);
        setMessage(res.message || null);
        setMessageType(res.valid ? 'success' : 'info');
      }
      setResolved(true);
    };
    fetchStatus();
  }, [request, config.endpoint]);

  const handleActivate = async () => {
    const res = await request('POST', { value: inputValue });
    if (res) {
      cache.set(config.endpoint, { ...res, value: inputValue });
      setValid(res.valid);
      setMessage(res.message);
      setMessageType(res.valid ? 'success' : 'error');
    }
  };

  const handleDeactivate = async () => {
    const res = await request('POST', { value: '', action: 'deactivate' });
    if (res) {
      cache.set(config.endpoint, res);
      setInputValue('');
      setValid(false);
      setShowManual(false);
      setConfirmingDisconnect(false);
      // In connect mode show a branded confirmation rather than the raw API message.
      setMessage(config.connect ? (config.disconnectedLabel ?? 'Universally disconnected') : res.message);
      setMessageType('info');
    }
  };

  const statusClass = valid ? 'valid' : messageType === 'error' ? 'invalid' : '';
  const feedback = error ?? message;
  const feedbackClass = error ? 'error' : messageType;

  // The raw key input + Activate/Deactivate row (shared by both modes).
  const inputRow = (
    <div className="wp-panel-api-key__input-row">
      <input
        type="text"
        id={fieldId}
        className={`wp-panel-api-key__input ${statusClass}`}
        value={valid ? maskKey(inputValue) : inputValue}
        onChange={(e) => {
          setInputValue(e.target.value);
          setMessage(null);
        }}
        placeholder={loading ? '' : (config.placeholder ?? 'Enter your API key')}
        disabled={loading || valid}
      />
      {valid ? (
        <button
          type="button"
          className="wp-panel-api-key__button wp-panel-api-key__button--deactivate"
          onClick={handleDeactivate}
          disabled={loading}
        >
          {loading ? 'Working...' : 'Deactivate'}
        </button>
      ) : (
        <button
          type="button"
          className="wp-panel-api-key__button wp-panel-api-key__button--validate"
          onClick={handleActivate}
          disabled={loading || !inputValue.trim()}
        >
          {loading ? 'Validating...' : 'Activate'}
        </button>
      )}
    </div>
  );

  const feedbackEl = feedback ? (
    <div className={`wp-panel-api-key__feedback wp-panel-api-key__feedback--${feedbackClass}`}>
      {feedbackClass === 'success' && <span className="wp-panel-api-key__checkmark">&#10003;</span>}
      {feedback}
    </div>
  ) : null;

  // Connect mode renders feedback as a notice/alert box rather than plain text.
  const noticeEl = feedback ? (
    <div className={`wp-panel-api-key__notice wp-panel-api-key__notice--${feedbackClass}`} role="status">
      {feedback}
    </div>
  ) : null;

  // Connect mode: launch the hosted onboarding instead of pasting a key.
  if (config.connect) {
    // Hold the layout until we know the connection state — no Connect→Connected flicker.
    if (!resolved) {
      return (
        <div className="wp-panel-api-key">
          <div className="wp-panel-api-key__checking">Checking connection…</div>
        </div>
      );
    }

    if (valid) {
      return (
        <div className="wp-panel-api-key wp-panel-api-key--status">
          <div className="wp-panel-api-key__connected">
            <span className="wp-panel-api-key__connected-badge">
              <span className="wp-panel-api-key__checkmark">&#10003;</span>
              {config.connectedLabel ?? 'Your site is connected to Universally'}
            </span>
            <button
              type="button"
              className="wp-panel-api-key__disconnect"
              onClick={() => setConfirmingDisconnect(true)}
              disabled={loading}
            >
              {loading ? 'Working...' : (config.disconnectLabel ?? 'Disconnect')}
            </button>
          </div>

          {confirmingDisconnect && (
            <Modal
              title={config.disconnectConfirmTitle ?? 'Disconnect from Universally'}
              onRequestClose={() => {
                if (!loading) setConfirmingDisconnect(false);
              }}
              isDismissible={!loading}
              shouldCloseOnClickOutside={!loading}
              shouldCloseOnEsc={!loading}
              size="small"
              className="wp-panel-api-key__disconnect-modal"
            >
              <p style={{ marginTop: 0 }}>
                {config.disconnectConfirmLabel ??
                  'Disconnect this site from Universally? Translation will stop until you reconnect.'}
              </p>
              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
                <Button variant="tertiary" onClick={() => setConfirmingDisconnect(false)} disabled={loading}>
                  {config.disconnectCancelLabel ?? 'Cancel'}
                </Button>
                <Button variant="primary" isDestructive onClick={handleDeactivate} disabled={loading}>
                  {loading ? 'Disconnecting…' : (config.disconnectConfirmButton ?? 'Yes, disconnect')}
                </Button>
              </div>
            </Modal>
          )}
          <div className="wp-panel-api-key__status">
            <span className="wp-panel-api-key__status-dot" aria-hidden="true" />
            <span className="wp-panel-api-key__status-label">
              {config.statusLabel ?? 'API status'}:{' '}
              <strong className="wp-panel-api-key__status-value">
                {config.statusValue ?? 'Operational'}
              </strong>
            </span>
          </div>
        </div>
      );
    }

    return (
      <div className="wp-panel-api-key">
        {config.connectDescription && (
          <p className="wp-panel-api-key__connect-desc">{config.connectDescription}</p>
        )}
        {config.connectUrl && (
          <a className="wp-panel-api-key__connect-btn" href={config.connectUrl}>
            {config.connectLabel ?? 'Connect to Universally'}
          </a>
        )}
        {showManual ? (
          <div className="wp-panel-api-key__manual">{inputRow}</div>
        ) : (
          <button
            type="button"
            className="wp-panel-api-key__manual-toggle"
            onClick={() => setShowManual(true)}
          >
            {config.manualLabel ?? 'Already have an API key? Enter it manually'}
          </button>
        )}
        {noticeEl}
      </div>
    );
  }

  // Default mode (unchanged): raw key input.
  return (
    <div className="wp-panel-api-key">
      {inputRow}
      {feedbackEl}
    </div>
  );
}
