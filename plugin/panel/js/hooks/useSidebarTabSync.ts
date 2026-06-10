import { useEffect } from 'react';

/**
 * Keep the WordPress admin sidebar submenu highlight in sync with the active tab.
 *
 * The submenu items (added in Panel.php::registerTabSubmenus) deep-link to tabs
 * via a URL hash, but WordPress decides the "current" item server-side from the
 * page slug alone — it can't see the hash, so it always highlights the first
 * (General) item. This effect re-points the `current` class client-side whenever
 * the active tab changes, so top-tab clicks, sidebar clicks, and initial load all
 * highlight the right item.
 *
 * Each submenu anchor maps to a tab: items with a `#tab` fragment use that id; the
 * bare page link (no fragment) is the first tab. No-op when the menu isn't present
 * (e.g. submenuTabs disabled).
 */
export const useSidebarTabSync = (panelId: string, activeTab: string, tabIds: string[]) => {
  const tabIdsKey = tabIds.join('|');

  useEffect(() => {
    if (!activeTab) return;

    const firstTab = tabIdsKey.split('|').filter(Boolean)[0] ?? '';
    const menu = document.getElementById(`toplevel_page_${panelId}`);
    if (!menu) return;

    const items = menu.querySelectorAll<HTMLLIElement>('.wp-submenu li');
    items.forEach((li) => {
      if (li.classList.contains('wp-submenu-head')) return;
      const anchor = li.querySelector('a');
      if (!anchor) return;

      const href = anchor.getAttribute('href') ?? '';
      const hashIdx = href.indexOf('#');
      let itemTab: string | null = null;
      if (hashIdx !== -1) {
        itemTab = href.slice(hashIdx + 1);
      } else if (href.includes(`page=${panelId}`)) {
        // Bare page link (no fragment) represents the first tab.
        itemTab = firstTab;
      }

      const isActive = itemTab !== null && itemTab === activeTab;
      li.classList.toggle('current', isActive);
      if (isActive) {
        anchor.setAttribute('aria-current', 'page');
      } else {
        anchor.removeAttribute('aria-current');
      }
    });
  }, [panelId, activeTab, tabIdsKey]);
};
