import { useEffect, useRef, useState } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { Modal, Spinner, Button, Notice } from '@wordpress/components';

interface DisplayInfo {
  workspaceName?: string;
  ownerEmail?: string;
  siteName?: string;
  siteDomain?: string;
}

interface ExchangeResponse {
  success: boolean;
  exchangeId?: string;
  displayInfo?: DisplayInfo;
  alreadyConnected?: boolean;
  code?: string;
  message?: string;
}

interface CommitResponse {
  success: boolean;
  message?: string;
  displayInfo?: DisplayInfo;
}

type Phase = 'verifying' | 'confirm' | 'connecting' | 'success' | 'error';

// How long the success state is shown before handing off to onActivated.
// Gives the user visual confirmation of which workspace they connected to,
// without parking them on the confirmation indefinitely. A countdown is
// rendered so the user sees the wait is intentional.
const SUCCESS_HOLD_SECONDS = 3;

interface Props {
  token: string;
  onClose: () => void;
  onActivated: () => void;
}

const COMMIT_ERROR = 'Could not save your API key. Try again.';

export function ActivationModal({ token, onClose, onActivated }: Props) {
  const [phase, setPhase] = useState<Phase>('verifying');
  const [errorMessage, setErrorMessage] = useState<string>('');
  const [displayInfo, setDisplayInfo] = useState<DisplayInfo>({});
  const [alreadyConnected, setAlreadyConnected] = useState(false);
  const [secondsLeft, setSecondsLeft] = useState(SUCCESS_HOLD_SECONDS);
  const exchangeIdRef = useRef<string | null>(null);
  const exchangeStartedRef = useRef(false);

  // Hold the latest onActivated in a ref so the success-phase effect doesn't
  // re-run whenever the parent re-renders and passes a fresh callback.
  const onActivatedRef = useRef(onActivated);
  useEffect(() => {
    onActivatedRef.current = onActivated;
  }, [onActivated]);

  // Drive the success countdown and the final handoff. One effect, one cleanup —
  // covers React 18 StrictMode's double-invocation and any mid-flight unmount.
  useEffect(() => {
    if (phase !== 'success') return;
    setSecondsLeft(SUCCESS_HOLD_SECONDS);
    const interval = window.setInterval(() => {
      setSecondsLeft((s) => Math.max(0, s - 1));
    }, 1000);
    const timeout = window.setTimeout(() => {
      onActivatedRef.current();
    }, SUCCESS_HOLD_SECONDS * 1000);
    return () => {
      window.clearInterval(interval);
      window.clearTimeout(timeout);
    };
  }, [phase]);

  // Exchange runs exactly once on mount. The token is single-use — calling twice
  // would burn a second token (well, the API would just return ACTIVATION_TOKEN_USED).
  useEffect(() => {
    if (exchangeStartedRef.current) return;
    exchangeStartedRef.current = true;

    (async () => {
      try {
        const res = (await apiFetch({
          path: '/universally/v1/activation/exchange',
          method: 'POST',
          data: { token },
        })) as ExchangeResponse;

        if (!res.success || !res.exchangeId) {
          setErrorMessage(res.message || 'Could not activate. Please try again.');
          setPhase('error');
          return;
        }

        exchangeIdRef.current = res.exchangeId;
        setDisplayInfo(res.displayInfo || {});
        setAlreadyConnected(!!res.alreadyConnected);
        setPhase('confirm');
      } catch (err) {
        // Network failure or REST permission denial bubbles here.
        setErrorMessage('Could not reach Universally. Try again, or paste your API key manually below.');
        setPhase('error');
      }
    })();
  }, [token]);

  const handleConfirm = async () => {
    // Guard against double-fire: the phase check survives React's batched state
    // updates because the rendered button's closure captures the previous render's
    // phase value, but our ref reads the current value synchronously.
    if (!exchangeIdRef.current || phase !== 'confirm') return;
    setPhase('connecting');
    try {
      const res = (await apiFetch({
        path: '/universally/v1/activation/commit',
        method: 'POST',
        data: { exchangeId: exchangeIdRef.current },
      })) as CommitResponse;

      if (!res.success) {
        setErrorMessage(res.message || COMMIT_ERROR);
        setPhase('error');
        return;
      }

      // Merge any displayInfo the commit response returned (defensive — the
      // exchange already populated it, but the API may refine it).
      if (res.displayInfo) setDisplayInfo((prev) => ({ ...prev, ...res.displayInfo }));
      setPhase('success');
    } catch (err) {
      setErrorMessage(COMMIT_ERROR);
      setPhase('error');
    }
  };

  const handleCancel = () => {
    if (exchangeIdRef.current) {
      // Fire-and-forget; the transient self-expires anyway.
      void apiFetch({
        path: '/universally/v1/activation/cancel',
        method: 'POST',
        data: { exchangeId: exchangeIdRef.current },
      }).catch(() => {});
    }
    onClose();
  };

  // Block Esc/X close during in-flight network work — half-committed state is worse than waiting.
  const dismissable = phase === 'confirm' || phase === 'error';
  const onRequestClose = dismissable ? handleCancel : () => {};

  const workspaceLabel =
    displayInfo.workspaceName || displayInfo.siteName || displayInfo.siteDomain || 'your Universally workspace';

  return (
    <Modal
      title="Connect to Universally"
      onRequestClose={onRequestClose}
      isDismissible={dismissable}
      shouldCloseOnClickOutside={dismissable}
      shouldCloseOnEsc={dismissable}
    >
      {phase === 'verifying' && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '8px 0' }}>
          <Spinner />
          <span>Verifying activation link…</span>
        </div>
      )}

      {phase === 'confirm' && (
        <>
          <p style={{ marginTop: 0 }}>
            You&rsquo;re about to connect this site to <strong>{workspaceLabel}</strong>.
          </p>
          <ul style={{ margin: '0 0 16px 16px', padding: 0 }}>
            {displayInfo.workspaceName && (
              <li>
                Workspace: <strong>{displayInfo.workspaceName}</strong>
              </li>
            )}
            {displayInfo.ownerEmail && (
              <li>
                Owner: <strong>{displayInfo.ownerEmail}</strong>
              </li>
            )}
            {displayInfo.siteName && (
              <li>
                Site: <strong>{displayInfo.siteName}</strong>
              </li>
            )}
          </ul>
          {alreadyConnected && (
            <Notice status="warning" isDismissible={false}>
              This will replace your existing connection.
            </Notice>
          )}
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
            <Button variant="tertiary" onClick={handleCancel}>
              Cancel
            </Button>
            <Button variant="primary" onClick={handleConfirm}>
              Confirm and connect
            </Button>
          </div>
        </>
      )}

      {phase === 'connecting' && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '8px 0' }}>
          <Spinner />
          <span>Connecting…</span>
        </div>
      )}

      {phase === 'success' && (
        <div style={{ textAlign: 'center', padding: '12px 0 4px' }}>
          <div
            aria-hidden="true"
            style={{
              width: 48,
              height: 48,
              borderRadius: '50%',
              backgroundColor: '#00a32a',
              color: '#fff',
              display: 'inline-flex',
              alignItems: 'center',
              justifyContent: 'center',
              fontSize: 28,
              lineHeight: 1,
              margin: '0 auto 14px',
            }}
          >
            ✓
          </div>
          <div style={{ fontSize: 16, fontWeight: 500 }}>
            {displayInfo.workspaceName
              ? `Connected to ${displayInfo.workspaceName}.`
              : 'Connected to Universally.'}
          </div>
          <div style={{ marginTop: 8, fontSize: 13, opacity: 0.7 }}>
            Closing in {secondsLeft}s…
          </div>
        </div>
      )}

      {phase === 'error' && (
        <>
          <Notice status="error" isDismissible={false}>
            {errorMessage}
          </Notice>
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
            <Button variant="primary" onClick={handleCancel}>
              Dismiss
            </Button>
          </div>
        </>
      )}
    </Modal>
  );
}
