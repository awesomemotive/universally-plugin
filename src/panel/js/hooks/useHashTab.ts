import { useState, useCallback } from 'react';

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

  return { activeTab, setActiveTab };
};