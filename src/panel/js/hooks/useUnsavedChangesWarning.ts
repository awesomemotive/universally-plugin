import { useEffect } from 'react';

/**
 * Shows a browser confirmation dialog when attempting to leave with unsaved changes.
 */
export const useUnsavedChangesWarning = (hasUnsavedChanges: boolean) => {
  useEffect(() => {
    if (!hasUnsavedChanges) {
      return;
    }

    const handleBeforeUnload = (event: BeforeUnloadEvent) => {
      // Standard way to trigger the browser's confirmation dialog
      event.preventDefault();
      // For older browsers
      event.returnValue = '';
      return '';
    };

    window.addEventListener('beforeunload', handleBeforeUnload);

    return () => {
      window.removeEventListener('beforeunload', handleBeforeUnload);
    };
  }, [hasUnsavedChanges]);
};