import { useEffect, useState } from 'react';
import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { PanelProvider } from './hooks/usePanelState';
import { Panel } from './components/Panel';
import { ActivationModal } from './components/ActivationModal';
import type { PanelDataWithOnboarding } from './types';

// Register field types
import './fields';

// Styles
import './style.scss';

declare global {
  interface Window {
    universallyPanelData?: PanelDataWithOnboarding;
  }
}

function App({ panelData }: { panelData: PanelDataWithOnboarding }) {
  const [activationToken, setActivationToken] = useState<string | null>(null);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const token = params.get('activation_token');
    if (!token) return;

    // Strip the token from the URL immediately:
    // - keeps it out of further browser-history entries
    // - prevents a refresh from re-opening the modal (token would already be consumed)
    // - keeps it out of any Referer header the admin generates by clicking external links
    params.delete('activation_token');
    const qs = params.toString();
    window.history.replaceState({}, '', window.location.pathname + (qs ? `?${qs}` : ''));

    setActivationToken(token);
  }, []);

  return (
    <PanelProvider panelData={panelData}>
      <Panel
        config={panelData.config}
        parsed={panelData.parsed}
        onboarding={panelData.onboarding}
      />
      {activationToken && (
        <ActivationModal
          token={activationToken}
          onClose={() => setActivationToken(null)}
          onActivated={() => {
            // Full reload so the existing api-key field re-fetches via its own GET /validate-api-key
            // path. The field caches its response at module level; rather than reach in and bust
            // that cache, we let the new page mount cleanly.
            window.location.reload();
          }}
        />
      )}
    </PanelProvider>
  );
}

function init(): void {
  const panelData = window.universallyPanelData;

  if (!panelData) {
    console.error('[WP Panel] No panel data found');
    return;
  }

  // Configure REST API nonce for @wordpress/api-fetch
  if (panelData.restNonce) {
    apiFetch.use(apiFetch.createNonceMiddleware(panelData.restNonce));
  }

  const containerId = `universally-panel-${panelData.config.id}`;
  const container = document.getElementById(containerId);

  if (!container) {
    console.error(`[WP Panel] Container #${containerId} not found`);
    return;
  }

  const root = createRoot(container);
  root.render(<App panelData={panelData} />);
}

// Initialize
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
