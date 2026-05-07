import { useMemo, useEffect, useRef } from 'react';
import type { FieldItem } from '../types';

interface UseTabErrorsOptions {
  fieldsByTab: Record<string, FieldItem[]>;
  errors: Record<string, string | undefined>;
  messageType: string | undefined;
  activeTab: string;
  tabIds: string[];
  onNavigate: (tabId: string) => void;
}

/**
 * Tracks which tabs have errors and auto-navigates to first error tab on save failure.
 */
export const useTabErrors = ({
  fieldsByTab,
  errors,
  messageType,
  activeTab,
  tabIds,
  onNavigate,
}: UseTabErrorsOptions): Set<string> => {
  const handledErrorRef = useRef<boolean>(false);

  // Calculate which tabs have errors
  const tabsWithErrors = useMemo(() => {
    const errorFieldIds = Object.entries(errors)
      .filter(([, error]) => error)
      .map(([fieldId]) => fieldId);

    if (errorFieldIds.length === 0) return new Set<string>();

    const tabErrors = new Set<string>();
    for (const [tabId, fields] of Object.entries(fieldsByTab)) {
      const hasError = fields.some((field) => errorFieldIds.includes(field.id));
      if (hasError) tabErrors.add(tabId);
    }
    return tabErrors;
  }, [errors, fieldsByTab]);

  // Auto-navigate to first tab with errors when save fails (only once per error)
  useEffect(() => {
    // Reset flag when errors are cleared
    if (messageType !== 'error' || tabsWithErrors.size === 0) {
      handledErrorRef.current = false;
      return;
    }

    // Only navigate once per error occurrence
    if (handledErrorRef.current) {
      return;
    }

    // Navigate to first tab with errors if current tab has no errors
    if (!tabsWithErrors.has(activeTab)) {
      const firstTabWithError = tabIds.find((id) => tabsWithErrors.has(id));
      if (firstTabWithError) {
        onNavigate(firstTabWithError);
      }
    }

    handledErrorRef.current = true;
  }, [messageType, tabsWithErrors, tabIds, activeTab, onNavigate]);

  return tabsWithErrors;
};