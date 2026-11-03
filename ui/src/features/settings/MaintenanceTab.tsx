/**
 * Maintenance tab.
 *
 * Read-only diagnostics plus the migration runner. The dry run is the primary
 * button: `POST /settings/maintenance/migrate` defaults to `dry_run: true` for
 * the same reason — a schema change should be asked for twice.
 */

import { useTranslation } from 'react-i18next';

import { Alert, Button, Spinner } from '@/components/ui';

import { useMaintenance, useMigration } from './useSettings';

const TH = 'px-2 py-1 text-start text-xs font-semibold uppercase tracking-wide text-[var(--color-muted)]';
const TD = 'px-2 py-1 text-sm';

function Row({ label, value }: { label: string; value: string }) {
  return (
    <tr className="border-b border-[var(--color-border)] last:border-0">
      <th scope="row" className={`${TH} w-1/3 normal-case`}>
        {label}
      </th>
      <td className={TD}>{value}</td>
    </tr>
  );
}

export function MaintenanceTab({ labelledBy }: { labelledBy: string }) {
  const { t } = useTranslation();
  const query = useMaintenance();
  const migration = useMigration();

  if (query.isPending) {
    return (
      <div className="flex items-center gap-2 text-sm text-[var(--color-muted)]">
        <Spinner /> {t('ui_loading')}
      </div>
    );
  }

  if (query.isError) {
    return (
      <Alert
        title={t('ui_api_unavailable')}
        action={<Button onClick={() => void query.refetch()}>{t('ui_retry')}</Button>}
      >
        {query.error instanceof Error ? query.error.message : null}
      </Alert>
    );
  }

  const { migrations, diagnostics } = query.data;
  const log = migration.data?.run.log ?? [];

  return (
    <section aria-labelledby={labelledBy} className="flex flex-col gap-4">
      <p className="m-0 text-sm text-[var(--color-muted)]">{t('set_maint_intro')}</p>

      <div className="card flex flex-col gap-2 p-4">
        <h3 className="m-0 text-sm font-semibold">{t('set_maint_diagnostics')}</h3>
        <table className="w-full border-collapse">
          <tbody>
            <Row label={t('set_maint_version')} value={diagnostics.app_version} />
            <Row label={t('set_maint_db')} value={`${diagnostics.database} · ${diagnostics.db_version}`} />
            <Row label="PHP" value={diagnostics.php_version} />
            <Row label={t('set_maint_timezone')} value={`${diagnostics.timezone} · ${diagnostics.now}`} />
            <Row
              label={t('set_maint_encryption')}
              value={diagnostics.encryption ? t('set_maint_encryption_ok') : t('set_maint_encryption_missing')}
            />
            <Row label={t('set_maint_ready')} value={migrations.ready ? t('set_maint_ready') : t('set_maint_none')} />
          </tbody>
        </table>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="card flex flex-col gap-2 p-4">
          <h3 className="m-0 text-sm font-semibold">{t('set_maint_applied')}</h3>
          {migrations.applied.length === 0 ? (
            <p className="m-0 text-sm text-[var(--color-muted)]">{t('set_maint_none')}</p>
          ) : (
            <ul className="m-0 flex list-none flex-col gap-1 p-0 text-sm">
              {migrations.applied.map((name) => (
                <li key={name}>
                  <code className="text-xs">{name}</code>
                </li>
              ))}
            </ul>
          )}
        </div>
        <div className="card flex flex-col gap-2 p-4">
          <h3 className="m-0 text-sm font-semibold">{t('set_maint_pending')}</h3>
          {migrations.pending.length === 0 ? (
            <p className="m-0 text-sm text-[var(--color-muted)]">{t('set_maint_none')}</p>
          ) : (
            <ul className="m-0 flex list-none flex-col gap-1 p-0 text-sm">
              {migrations.pending.map((name) => (
                <li key={name}>
                  <code className="text-xs">{name}</code>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <Button variant="secondary" disabled={migration.isPending} onClick={() => migration.mutate(true)}>
          {t('set_maint_dry')}
        </Button>
        <Button variant="primary" disabled={migration.isPending} onClick={() => migration.mutate(false)}>
          {t('set_maint_run')}
        </Button>
        {migration.isPending ? <Spinner /> : null}
      </div>

      {log.length === 0 ? null : (
        <div className="card flex flex-col gap-2 p-4">
          <h3 className="m-0 text-sm font-semibold">{t('set_maint_applied')}</h3>
          <pre className="m-0 overflow-x-auto text-xs">{log.join('\n')}</pre>
        </div>
      )}
    </section>
  );
}
