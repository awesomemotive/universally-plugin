import { useState, useCallback, useEffect } from 'react';

/**
 * Manages tab state synced with URL hash.
 * Reads initial tab from hash, updates hash on tab change.
 */
export const useHashTab = (tabIds: string[]) => {
  const [activeTab, setActiveTabState] = useState<string>(() => {
    if (tabIds.length === 0) return '';
    const hash = window.location.hash.replace('#', '');
    return hash && tabIds.includes(hash) ? hash : tabIds[0];
  });

  const setActiveTab = useCallback((tabName: string) => {
    setActiveTabState(tabName);
    window.location.hash = tabName;
  }, []);

  // Sync when the hash changes externally — e.g. clicking a sidebar submenu link
  // that points at #tab while the panel is already open (no page reload fires).
  const tabIdsKey = tabIds.join('|');
  useEffect(() => {
    const ids = tabIdsKey.split('|').filter(Boolean);
    const onHashChange = () => {
      const hash = window.location.hash.replace('#', '');
      if (hash && ids.includes(hash)) {
        setActiveTabState(hash);
      }
    };
    window.addEventListener('hashchange', onHashChange);
    return () => window.removeEventListener('hashchange', onHashChange);
  }, [tabIdsKey]);

  return { activeTab, setActiveTab };
};