/**
 * `/app/logs` — the audit trail (admin+).
 *
 * Retention and the purge live behind the same screen because they are the same
 * question ("what does the log keep, and for how long?"). The list itself is
 * read-only: an audit row is never edited or deleted one by one, only aged out
 * by the retention policy or a deliberate purge.
 *
 * Only a superadmin sees the purge card — a company admin may read the log but
 * retention is an instance-wide policy (`lib/panel/logs.php`).
 */

import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Alert, Button, Field, Input, Select, Spinner } from '@/components/ui';
import { useCurrentSession } from '@/features/auth/SessionContext';

import type { LogRow } from './api';
import { useLogs, usePurgeLogs } from './useLogs';

const TH = 'px-2 py-1 text-start text-xs font-semibold uppercase tracking-wide text-[var(--color-muted)]';
const TD = 'px-2 py-1 align-top text-sm';

/** Entity type → catalogue key. Literal, so `bin/check-i18n.php` can see them. */
const ENTITY_LABEL_KEYS: Record<string, string> = {
  device: 'logs_entity_device',
  setting: 'logs_entity_setting',
  source: 'logs_entity_source',
  field: 'logs_entity_field',
  api_key: 'logs_entity_api_key',
  user: 'logs_entity_user',
  migration: 'logs_entity_migration',
  logs: 'logs_entity_logs',
  system: 'logs_entity_system',
};

type FilterState = {
  q: string;
  action: string;
  entity: string;
  company: string;
  from: string;
  to: string;
};

const EMPTY_FILTERS: FilterState = { q: '', action: '', entity: '', company: '', from: '', to: '' };

/**
 * Human-readable summary of a row: the changed fields, or the note when the
 * action does not carry a diff (a rotation, a purge).
 */
function rowDetails(row: LogRow): string {
  const parts: string[] = [];
  for (const [field, pair] of Object.entries(row.changes ?? {})) {
    const old = pair[0] ?? '—';
    const next = pair[1] ?? '—';
    parts.push(`${field}: ${old} → ${next}`);
  }
  if (row.note !== null && row.note !== '') {
    parts.push(row.note);
  }
  return parts.join(' · ');
}

export function LogsPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.resolvedLanguage ?? 'en-US';
  const session = useCurrentSession();

  const [filters, setFilters] = useState<FilterState>(EMPTY_FILTERS);
  const [page, setPage] = useState(1);
  const [before, setBefore] = useState('');

  const query = useLogs({ ...filters, page });
  const purge = usePurgeLogs();

  const update = (patch: Partial<FilterState>) => {
    setFilters((current) => ({ ...current, ...patch }));
    setPage(1);
  };

  const meta = query.data?.meta;
  const payload = query.data?.payload;
  const rows = payload?.rows ?? [];

  /** Dry run first: the confirmation quotes a number instead of a warning. */
  const runPurge = async () => {
    if (before === '') {
      return;
    }
    try {
      const preview = await purge.mutateAsync({ before, dryRun: true });
      const message = t('logs_purge_preview', { count: preview.deleted, date: before });
      if (!window.confirm(message)) {
        return;
      }
      await purge.mutateAsync({ before, dryRun: false });
    } catch {
      // The hook already toasted the failure.
    }
  };

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

  const options = meta?.filter_options;
  const hasFilters = Object.values(filters).some((value) => value !== '');

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <h1 className="m-0 text-xl font-semibold">{t('logs_title')}</h1>
        <p className="m-0 text-sm text-[var(--color-muted)]">{t('logs_intro')}</p>
      </div>

      <div className="card flex flex-wrap items-end gap-3 p-4">
        <Field label={t('logs_filter_search')} htmlFor="logs-q" className="min-w-48 grow">
          <Input id="logs-q" value={filters.q} onChange={(event) => update({ q: event.target.value })} />
        </Field>

        <Field label={t('logs_filter_action')} htmlFor="logs-action" className="min-w-36">
          <Select id="logs-action" value={filters.action} onChange={(event) => update({ action: event.target.value })}>
            <option value="">{t('all')}</option>
            {(options?.actions ?? []).map((action) => (
              <option key={action} value={action}>
                {t(`ui_ev_${action}`, { defaultValue: action })}
              </option>
            ))}
          </Select>
        </Field>

        <Field label={t('logs_filter_entity')} htmlFor="logs-entity" className="min-w-36">
          <Select id="logs-entity" value={filters.entity} onChange={(event) => update({ entity: event.target.value })}>
            <option value="">{t('all')}</option>
            {(options?.entities ?? []).map((entity) => (
              <option key={entity} value={entity}>
                {t(ENTITY_LABEL_KEYS[entity] ?? 'logs_entity_system', { defaultValue: entity })}
              </option>
            ))}
          </Select>
        </Field>

        {meta?.scope === 'all' && (options?.companies.length ?? 0) > 0 ? (
          <Field label={t('logs_filter_company')} htmlFor="logs-company" className="min-w-40">
            <Select
              id="logs-company"
              value={filters.company}
              onChange={(event) => update({ company: event.target.value })}
            >
              <option value="">{t('all')}</option>
              {(options?.companies ?? []).map((company) => (
                <option key={company} value={company}>
                  {company}
                </option>
              ))}
            </Select>
          </Field>
        ) : null}

        <Field label={t('logs_filter_from')} htmlFor="logs-from" className="min-w-36">
          <Input
            id="logs-from"
            type="date"
            value={filters.from}
            onChange={(event) => update({ from: event.target.value })}
          />
        </Field>
        <Field label={t('logs_filter_to')} htmlFor="logs-to" className="min-w-36">
          <Input id="logs-to" type="date" value={filters.to} onChange={(event) => update({ to: event.target.value })} />
        </Field>

        <Button variant="ghost" disabled={!hasFilters} onClick={() => update(EMPTY_FILTERS)}>
          {t('logs_filter_clear')}
        </Button>
      </div>

      <p aria-live="polite" className="m-0 text-sm text-[var(--color-muted)]">
        {t('logs_total', { total: payload?.total ?? 0 })}
        {' · '}
        {(meta?.retention_days ?? 0) > 0
          ? t('logs_retention', { days: meta?.retention_days })
          : t('logs_retention_off')}
        {' · '}
        {meta?.last_purge == null
          ? t('logs_never_purged')
          : t('logs_last_purge', { when: new Date(meta.last_purge.created_at).toLocaleString(locale) })}
      </p>

      <div className="card overflow-x-auto p-0">
        <table className="w-full border-collapse">
          <thead>
            <tr className="border-b border-[var(--color-border)]">
              <th className={TH}>{t('logs_col_time')}</th>
              <th className={TH}>{t('logs_col_actor')}</th>
              <th className={TH}>{t('logs_col_action')}</th>
              <th className={TH}>{t('logs_col_entity')}</th>
              <th className={TH}>{t('logs_col_company')}</th>
              <th className={TH}>{t('logs_col_details')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id} className="border-b border-[var(--color-border)] last:border-0">
                <td className={`${TD} whitespace-nowrap`}>{new Date(row.created_at).toLocaleString(locale)}</td>
                <td className={TD}>{row.actor}</td>
                <td className={TD}>{t(`ui_ev_${row.action}`, { defaultValue: row.action })}</td>
                <td className={TD}>
                  <span className="flex flex-col">
                    <span>
                      {t(ENTITY_LABEL_KEYS[row.entity] ?? 'logs_entity_system', { defaultValue: row.entity })}
                    </span>
                    {row.entity_label === null ? null : (
                      <span className="text-xs text-[var(--color-muted)]">{row.entity_label}</span>
                    )}
                  </span>
                </td>
                <td className={TD}>{row.company ?? '—'}</td>
                <td className={`${TD} max-w-[36rem] break-words`}>{rowDetails(row)}</td>
              </tr>
            ))}
            {rows.length === 0 ? (
              <tr>
                <td className={TD} colSpan={6}>
                  {t('logs_empty')}
                </td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </div>

      {(payload?.pages ?? 1) > 1 ? (
        <div className="flex items-center gap-2">
          <Button variant="ghost" disabled={page <= 1} onClick={() => setPage((current) => Math.max(1, current - 1))}>
            {t('previous')}
          </Button>
          <span className="text-sm text-[var(--color-muted)]">
            {t('page')} {page} {t('of')} {payload?.pages ?? 1}
          </span>
          <Button
            variant="ghost"
            disabled={page >= (payload?.pages ?? 1)}
            onClick={() => setPage((current) => current + 1)}
          >
            {t('next')}
          </Button>
        </div>
      ) : null}

      {session.capabilities.can_superadmin ? (
        <section className="card flex flex-col gap-3 p-4">
          <h2 className="m-0 text-base font-semibold">{t('logs_purge_title')}</h2>
          <p className="m-0 text-sm text-[var(--color-muted)]">{t('logs_purge_hint')}</p>
          <div className="flex flex-wrap items-end gap-3">
            <Field label={t('logs_filter_to')} htmlFor="logs-purge-before" className="min-w-40">
              <Input
                id="logs-purge-before"
                type="date"
                value={before}
                onChange={(event) => setBefore(event.target.value)}
              />
            </Field>
            <Button variant="danger" disabled={before === '' || purge.isPending} onClick={() => void runPurge()}>
              {purge.isPending ? <Spinner className="size-4" /> : null}
              {t('logs_purge_btn')}
            </Button>
          </div>
        </section>
      ) : null}
    </div>
  );
}
