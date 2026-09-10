import type { PanelNotice } from '../types';

interface NoticesProps {
  notices?: PanelNotice[];
}

/**
 * Panel-wide notices, rendered above the tabs bar so they show on every tab.
 * Non-dismissible by design: they describe site conditions that break the
 * plugin, and they disappear on their own once the condition is fixed.
 */
export function Notices({ notices }: NoticesProps) {
  if (!notices || notices.length === 0) {
    return null;
  }

  return (
    <div className="wp-panel__notices">
      {notices.map((notice) => {
        const isInfo = notice.type === 'info';

        return (
          <div
            key={notice.id}
            className={`wp-panel__notice-card wp-panel__notice-card--${notice.type}`}
            role={isInfo ? 'status' : 'alert'}
            data-notice-id={notice.id}
          >
            <span
              className={`dashicons ${isInfo ? 'dashicons-info' : 'dashicons-warning'} wp-panel__notice-card-icon`}
              aria-hidden="true"
            />
            <div className="wp-panel__notice-card-body">
              {notice.title && <strong className="wp-panel__notice-card-title">{notice.title}</strong>}
              <p className="wp-panel__notice-card-message">{notice.message}</p>
            </div>
            {notice.action && (
              /* Same-window link: the target is always a wp-admin screen. */
              <a className="wp-panel__notice-card-action" href={notice.action.href}>
                {notice.action.label}
              </a>
            )}
          </div>
        );
      })}
    </div>
  );
}
