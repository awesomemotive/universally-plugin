import type { PanelConfig, ParsedSchema, OnboardingData } from '../types';
import { usePanelState } from '../hooks/usePanelState';
import { useScrollThreshold } from '../hooks/useScrollThreshold';
import { useHashTab } from '../hooks/useHashTab';
import { useSidebarTabSync } from '../hooks/useSidebarTabSync';
import { useTabContent } from '../hooks/useTabContent';
import { useTabErrors } from '../hooks/useTabErrors';
import { useUnsavedChangesWarning } from '../hooks/useUnsavedChangesWarning';
import { Tab } from './Tab';
import { Onboarding } from './Onboarding';

interface PanelProps {
  config: PanelConfig;
  parsed: ParsedSchema;
  onboarding?: OnboardingData;
}

export function Panel({ config, parsed, onboarding }: PanelProps) {
  // If onboarding is active, render wizard instead
  if (onboarding && ['pending', 'in_progress'].includes(onboarding.state.status)) {
    return <Onboarding onboarding={onboarding} panelTitle={config.title} />;
  }

  const { state } = usePanelState();
  const { tabIds, tabs, fieldsByTab, sectionsByTab } = useTabContent(parsed);
  const { activeTab, setActiveTab } = useHashTab(tabIds);
  useSidebarTabSync(config.id, activeTab, tabIds);
  const isScrolled = useScrollThreshold(60);

  // Warn user when leaving with unsaved changes
  useUnsavedChangesWarning(state.modified);

  const tabsWithErrors = useTabErrors({
    fieldsByTab,
    errors: state.errors,
    messageType: state.messageType || '',
    activeTab,
    tabIds,
    onNavigate: setActiveTab,
  });

  return (
    <div className="wp-panel">
      <header className="wp-panel__header">
        <div className="wp-panel__header-inner">
          <div className="wp-panel__header-left">
            {config.logoPath ? (
              <img src={config.logoPath} alt={config.title} className="wp-panel__logo" />
            ) : (
              <h1>{config.title}</h1>
            )}
          </div>
          <div className="wp-panel__header-right">
            {config.headerActions?.map((action, index) => (
              <a
                key={index}
                href={action.href}
                className="wp-panel__header-action"
                target="_blank"
                rel="noopener noreferrer"
              >
                {action.iconPath ? (
                  <img src={action.iconPath} alt="" className="wp-panel__header-action-icon" />
                ) : action.icon ? (
                  <span className={`dashicons ${action.icon}`} />
                ) : null}
                <span>{action.label}</span>
              </a>
            ))}
          </div>
        </div>
      </header>

      <div className="wp-panel__content">
        {/* Tabs bar with status indicator */}
        <div className={`wp-panel__tabs-bar${isScrolled ? ' is-scrolled' : ''}`}>
          <div className="wp-panel__tabs-bar-inner">
            {tabs.length > 1 && (
              <div className="wp-panel__tabs">
                {tabs.map((tab) => {
                  const hasError = tabsWithErrors.has(tab.name);
                  return (
                    <button
                      key={tab.name}
                      className={`wp-panel__tab ${activeTab === tab.name ? 'is-active' : ''} ${hasError ? 'has-error' : ''}`}
                      onClick={() => setActiveTab(tab.name)}
                    >
                      {tab.title}
                      {hasError && <span className="wp-panel__tab-error-dot" />}
                    </button>
                  );
                })}
              </div>
            )}
            <div className="wp-panel__status-indicator">
              {state.message ? (
                <span className={`wp-panel__status-pill wp-panel__status-pill--${state.messageType}`}>
                  {state.message}
                </span>
              ) : state.modified ? (
                <span className="wp-panel__status-pill wp-panel__status-pill--unsaved">
                  <span className="wp-panel__status-dot" />
                  Unsaved
                </span>
              ) : null}
            </div>
          </div>
        </div>

        {/* Tab content */}
        <div className="wp-panel__tab-content">
          {activeTab && (
            <Tab
              tabId={activeTab}
              fields={fieldsByTab[activeTab] ?? []}
              sections={sectionsByTab[activeTab] ?? []}
            />
          )}
        </div>
      </div>
    </div>
  );
}
