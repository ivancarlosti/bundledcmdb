/**
 * `/app/settings` — the admin surface.
 *
 * One screen, tabs as a `?tab=` parameter (the same "URL is the state" rule the
 * grid follows, so a tab can be bookmarked and survives a reload). Which tabs
 * exist is decided by the session capabilities: `admin`/`superadmin` see the
 * settings, only `superadmin` sees the user roster.
 */

import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Alert } from '@/components/ui';
import { useCurrentSession } from '@/features/auth/SessionContext';

import { ApiKeysTab } from './ApiKeysTab';
import { ColumnsTab } from './ColumnsTab';
import { FieldsTab } from './FieldsTab';
import { MaintenanceTab } from './MaintenanceTab';
import { PermissionsTab } from './PermissionsTab';
import { SettingsGroupPanel } from './SettingsGroupPanel';
import { SourcesTab } from './SourcesTab';
import { UsersTab } from './UsersTab';

type Tab = {
  id: string;
  label_key: string;
  /** Capability that makes the tab visible (and its endpoint usable). */
  capability: string;
};

const TABS: Tab[] = [
  { id: 'general', label_key: 'set_tab_general', capability: 'settings.manage' },
  { id: 'columns', label_key: 'set_tab_columns', capability: 'settings.manage' },
  { id: 'storage', label_key: 'set_tab_storage', capability: 'settings.manage' },
  { id: 'sources', label_key: 'set_tab_sources', capability: 'settings.sources' },
  { id: 'api', label_key: 'set_tab_api', capability: 'settings.api_keys' },
  { id: 'fields', label_key: 'set_tab_fields', capability: 'settings.fields' },
  { id: 'logs', label_key: 'set_tab_logs', capability: 'settings.manage' },
  { id: 'maintenance', label_key: 'set_tab_maintenance', capability: 'settings.maintenance' },
  { id: 'permissions', label_key: 'set_tab_permissions', capability: 'permissions.manage' },
  { id: 'users', label_key: 'set_tab_users', capability: 'settings.users' },
];

const TAB_LINK = 'rounded-[var(--radius-md)] px-3 py-1.5 text-sm';

/**
 * Selected tab, mirrored into `?tab=`.
 *
 * `history.replaceState` keeps the back button meaningful (one entry for the
 * screen, not one per tab click) and an unknown or forbidden id falls back to
 * the first tab the role may open.
 */
function useTab(ids: string[]): [string, (id: string) => void] {
  const fallback = ids[0] ?? 'general';

  const read = (): string => {
    if (typeof window === 'undefined') {
      return fallback;
    }
    const requested = new URLSearchParams(window.location.search).get('tab') ?? '';
    return ids.includes(requested) ? requested : fallback;
  };

  const [tab, setTab] = useState<string>(read);

  useEffect(() => {
    const onPopState = () => {
      const requested = new URLSearchParams(window.location.search).get('tab') ?? '';
      setTab(ids.includes(requested) ? requested : fallback);
    };
    window.addEventListener('popstate', onPopState);
    return () => window.removeEventListener('popstate', onPopState);
  }, [ids, fallback]);

  const select = (id: string) => {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', id);
    window.history.replaceState(null, '', url);
    setTab(id);
  };

  return [tab, select];
}

export function SettingsPage() {
  const { t } = useTranslation();
  const session = useCurrentSession();
  // Memoised so the tab hook's `popstate` effect is not re-registered on every
  // render (the ids array is its dependency).
  const allowed = useMemo(
    () => TABS.filter((tab) => session.capabilities.permissions[tab.capability] === true),
    [session.capabilities.permissions],
  );
  const ids = useMemo(() => allowed.map((item) => item.id), [allowed]);
  const [tab, select] = useTab(ids);

  if (allowed.length === 0) {
    return <Alert title={t('ui_err_forbidden')}>{t('set_title')}</Alert>;
  }

  const headingId = 'settings-heading';

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <h1 id={headingId} className="m-0 text-xl font-semibold">
          {t('set_title')}
        </h1>
      </div>

      <div role="tablist" aria-labelledby={headingId} className="flex flex-wrap gap-1">
        {allowed.map((item) => (
          <button
            key={item.id}
            type="button"
            role="tab"
            id={`settings-tab-${item.id}`}
            aria-selected={tab === item.id}
            aria-controls={`settings-panel-${item.id}`}
            className={`${TAB_LINK} ${
              tab === item.id ? 'bg-[var(--color-surface-3)] font-semibold' : 'hover:bg-[var(--color-surface-2)]'
            }`}
            onClick={() => select(item.id)}
          >
            {t(item.label_key)}
          </button>
        ))}
      </div>

      <div
        role="tabpanel"
        id={`settings-panel-${tab}`}
        aria-labelledby={`settings-tab-${tab}`}
        className="flex flex-col gap-4"
      >
        {tab === 'general' ? (
          <SettingsGroupPanel groups={['general', 'assets']} intro={t('set_general_intro')} labelledBy={headingId} />
        ) : null}
        {tab === 'storage' ? (
          <SettingsGroupPanel groups={['storage', 's3']} intro={t('set_storage_intro')} labelledBy={headingId} />
        ) : null}
        {tab === 'columns' ? <ColumnsTab labelledBy={headingId} /> : null}
        {tab === 'sources' ? <SourcesTab labelledBy={headingId} /> : null}
        {tab === 'api' ? (
          <div className="flex flex-col gap-6">
            {session.capabilities.permissions['settings.manage'] ? (
              <SettingsGroupPanel groups={['api']} intro={t('set_api_intro')} labelledBy={headingId} />
            ) : null}
            <ApiKeysTab labelledBy={headingId} />
          </div>
        ) : null}
        {tab === 'fields' ? <FieldsTab labelledBy={headingId} /> : null}
        {tab === 'logs' ? (
          <SettingsGroupPanel groups={['logs']} intro={t('set_logs_intro')} labelledBy={headingId} />
        ) : null}
        {tab === 'maintenance' ? <MaintenanceTab labelledBy={headingId} /> : null}
        {tab === 'permissions' ? <PermissionsTab labelledBy={headingId} /> : null}
        {tab === 'users' ? <UsersTab labelledBy={headingId} /> : null}
      </div>
    </div>
  );
}
