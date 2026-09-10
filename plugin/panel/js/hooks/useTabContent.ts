import { useMemo } from 'react';
import type { ParsedSchema, FieldItem, SectionItem } from '../types';

interface TabInfo {
  name: string;
  title: string;
}

interface TabContent {
  /** Every tab id, hidden ones included — hash deep-links must still resolve. */
  tabIds: string[];
  /** Visible tabs only — what the tabs bar renders. */
  tabs: TabInfo[];
  fieldsByTab: Record<string, FieldItem[]>;
  sectionsByTab: Record<string, SectionItem[]>;
}

/**
 * Parses schema into tab content - groups fields and sections by tab.
 */
export const useTabContent = (parsed: ParsedSchema): TabContent => {
  return useMemo(() => {
    const tabIds = Object.keys(parsed.tabs);
    const fieldsByTab: Record<string, FieldItem[]> = {};
    const sectionsByTab: Record<string, SectionItem[]> = {};

    for (const tabId of tabIds) {
      fieldsByTab[tabId] = [];
      sectionsByTab[tabId] = [];
    }

    // Group sections by tab
    for (const section of Object.values(parsed.sections || {})) {
      const tabId = section._tab ?? 'general';
      if (sectionsByTab[tabId]) {
        sectionsByTab[tabId].push(section);
      }
    }

    // Group fields by tab
    for (const field of Object.values(parsed.fields)) {
      const tabId = field._tab ?? 'general';
      if (fieldsByTab[tabId]) {
        fieldsByTab[tabId].push(field);
      }
    }

    // Build tab info for the tabs bar. Hidden tabs are excluded here but stay in
    // tabIds, so useHashTab can still open them from a #tabId deep link.
    const tabs = tabIds
      .filter((id) => !parsed.tabs[id]?.hidden)
      .map((id) => ({
        name: id,
        title: parsed.tabs[id].label,
      }));

    return { tabIds, tabs, fieldsByTab, sectionsByTab };
  }, [parsed]);
};