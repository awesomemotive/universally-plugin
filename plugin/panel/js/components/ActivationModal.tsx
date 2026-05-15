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

type Phase = 'verifying' | 'confirm' | 'connecting' | 'error';

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
  const exchangeIdRef = useRef<string | null>(null);
  const exchangeStartedRef = useRef(false);

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

      onActivated();
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
            You're about to connect this site to <strong>{workspaceLabel}</strong>.
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
