/**
 * Audit log endpoints (`lib/panel/logs.php`).
 *
 * The list is server-paged and server-filtered, same as the device grid: the
 * table can grow past any client-side slice, so `q`/`action`/`entity`/`from`/`to`
 * are query parameters and `meta` carries the values the filter selects offer.
 */

import { apiRequest, apiRequestEnvelope, buildQuery } from '@/api/client';

export type LogFilters = {
  q: string;
  action: string;
  entity: string;
  company: string;
  from: string;
  to: string;
  page: number;
  per_page?: number;
};

/** One audit row. `changes` is `{field: [old, new]}` like the device history. */
export type LogRow = {
  id: number;
  asset_id: number | null;
  company: string | null;
  /** `device`, `setting`, `source`, `field`, `api_key`, `user`, `migration`, `logs`. */
  entity: string;
  entity_id: string | null;
  entity_label: string | null;
  action: string;
  actor: string;
  changes: Record<string, [string | null, string | null]>;
  note: string | null;
  created_at: string;
};

export type LogFilterOptions = {
  actions: string[];
  entities: string[];
  companies: string[];
};

export type LogLastPurge = { created_at: string; actor: string; note: string | null };

export type LogListMeta = {
  filters: {
    q: string;
    action: string;
    entity: string;
    actor: string;
    /** UTC boundaries (the inputs are inclusive local dates). */
    from: string | null;
    to: string | null;
    company: string | null;
  };
  /** `all` for an unbound account, otherwise the company the rows are scoped to. */
  scope: string;
  filter_options: LogFilterOptions;
  retention_days: number;
  last_purge: LogLastPurge | null;
};

export type LogListPayload = {
  rows: LogRow[];
  total: number;
  page: number;
  per_page: number;
  pages: number;
};

export type LogsResult = { payload: LogListPayload; meta: LogListMeta };

export async function fetchLogs(filters: LogFilters): Promise<LogsResult> {
  const query = buildQuery({
    q: filters.q,
    action: filters.action,
    entity: filters.entity,
    company: filters.company,
    from: filters.from,
    to: filters.to,
    page: filters.page,
    per_page: filters.per_page,
  });
  const result = await apiRequestEnvelope<LogListPayload>(`/logs${query}`);
  return { payload: result.data, meta: result.meta as LogListMeta };
}

export type PurgeResult = {
  /** Rows deleted, or rows that *would* be deleted on a dry run. */
  deleted: number;
  before: string;
  /** UTC boundary the date resolved to. */
  cutoff: string;
  dry_run: boolean;
  last_purge: LogLastPurge | null;
  retention_days: number;
};

export function purgeLogs(before: string, dryRun: boolean): Promise<PurgeResult> {
  return apiRequest<PurgeResult>('/logs/purge', { method: 'POST', body: { before, dry_run: dryRun } });
}
