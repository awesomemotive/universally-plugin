import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { PanelProvider } from './hooks/usePanelState';
import { Panel } from './components/Panel';
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
  root.render(
    <PanelProvider panelData={panelData}>
      <Panel
        config={panelData.config}
        parsed={panelData.parsed}
        onboarding={panelData.onboarding}
      />
    </PanelProvider>
  );
}

// Initialize
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
