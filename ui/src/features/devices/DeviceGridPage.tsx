import { useNavigate } from '@tanstack/react-router';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Alert, Button, Checkbox, Field, Input, Select, Spinner } from '@/components/ui';
import { useCurrentSession } from '@/features/auth/SessionContext';
import { DataGrid } from '@/features/grid/DataGrid';
import {
  applyFilters,
  applySort,
  hasActiveFilters,
  nextSort,
  sortedDir,
  type ByodFilter,
  type GridDefaults,
} from '@/features/grid/gridSearch';
import { useGridState } from '@/features/grid/useGridState';

import { deviceExportUrl } from './api';
import { buildColumns } from './columns';
import { useDevices, useMeta } from './useDevices';

const EXPORT_LINK_CLASS =
  'inline-flex items-center justify-center gap-1.5 rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-1.5 text-sm font-medium hover:bg-[var(--color-surface-2)]';

/**
 * The device grid.
 *
 * Sorting, filtering and paging are all server-side and all live in the URL, so
 * a view can be bookmarked, reloaded and shared, and sorting applies to the whole
 * result set instead of the loaded page.
 */
export function DeviceGridPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.resolvedLanguage ?? 'en-US';
  const navigate = useNavigate();
  const session = useCurrentSession();

  const metaQuery = useMeta();

  const defaults = useMemo<GridDefaults>(
    () => ({
      sort: metaQuery.data?.defaultSort.sort ?? 'updated_at',
      dir: metaQuery.data?.defaultSort.dir ?? 'desc',
      perPage: session.perPage,
    }),
    [metaQuery.data?.defaultSort.sort, metaQuery.data?.defaultSort.dir, session.perPage],
  );

  const { state, update, reset } = useGridState(defaults);
  const devicesQuery = useDevices(state);
  const [search, setSearch] = useState(state.q);

  // Debounced search: 300 ms after typing stops the URL (and the query) follow.
  useEffect(() => {
    const trimmed = search.trim();
    if (trimmed === state.q) {
      return;
    }
    const timer = window.setTimeout(() => {
      update((current) => applyFilters(current, { q: trimmed }));
    }, 300);
    return () => window.clearTimeout(timer);
  }, [search, state.q, update]);

  const columns = useMemo(
    () => (metaQuery.data === undefined ? [] : buildColumns(metaQuery.data, t, locale)),
    [metaQuery.data, t, locale],
  );

  const meta = metaQuery.data;
  const payload = devicesQuery.data?.payload;
  const rows = payload?.rows ?? [];
  const total = payload?.total ?? 0;
  const perPage = payload?.per_page ?? state.perPage;
  const page = payload?.page ?? state.page;
  const pages = payload?.pages ?? 1;
  const from = total === 0 ? 0 : (page - 1) * perPage + 1;
  const to = total === 0 ? 0 : from + rows.length - 1;
  const isFirstLoad = metaQuery.isPending || (devicesQuery.isPending && devicesQuery.data === undefined);
  // The stored page size may not be one of the offered sizes (settings changed
  // after it was saved), so it is always added instead of rendering a blank select.
  const pageSizeOptions = Array.from(
    new Set([...(session.pageSizes.length > 0 ? session.pageSizes : [10, 25, 50, 100]), state.perPage]),
  ).sort((a, b) => a - b);

  const onSort = (columnId: string) => {
    update((current) => applySort(current, nextSort(current, columnId, defaults)));
  };

  return (
    <section className="flex flex-col gap-3">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <h1 className="m-0 text-xl font-semibold">{t('ui_devices')}</h1>
          {devicesQuery.isFetching ? <Spinner className="size-4" /> : null}
          <span className="rounded-full bg-[var(--color-surface-3)] px-2 py-0.5 text-xs text-[var(--color-muted)]">
            {t('records')}: {total}
          </span>
        </div>
        <span className="text-xs text-[var(--color-muted)]">
          {t('signed_in_as')} {session.user.email}
        </span>
      </header>

      {metaQuery.isError ? (
        <Alert
          title={t('ui_api_unavailable')}
          action={<Button onClick={() => void metaQuery.refetch()}>{t('ui_retry')}</Button>}
        >
          <p className="m-0">{metaQuery.error instanceof Error ? metaQuery.error.message : ''}</p>
        </Alert>
      ) : null}

      {devicesQuery.isError ? (
        <Alert
          title={t('ui_api_unavailable')}
          action={<Button onClick={() => void devicesQuery.refetch()}>{t('ui_retry')}</Button>}
        >
          <p className="m-0">{devicesQuery.error instanceof Error ? devicesQuery.error.message : ''}</p>
        </Alert>
      ) : null}

      <div className="card flex flex-wrap items-end gap-3 p-3">
        <Field label={t('search_text')} className="min-w-56 flex-1">
          <Input
            type="search"
            value={search}
            placeholder={t('search_any')}
            onChange={(event) => setSearch(event.target.value)}
          />
        </Field>

        <Field label={t('source_filter')}>
          <Select
            value={state.source}
            onChange={(event) => update((current) => applyFilters(current, { source: event.target.value }))}
          >
            <option value="">{t('all_sources')}</option>
            {(meta?.sources ?? []).map((source) => (
              <option key={source.slug} value={source.slug}>
                {source.label}
              </option>
            ))}
          </Select>
        </Field>

        <Field label={t('col_status')}>
          <Select
            value={state.status}
            onChange={(event) => update((current) => applyFilters(current, { status: event.target.value }))}
          >
            <option value="">{t('all')}</option>
            {(meta?.statuses ?? []).map((status) => (
              <option key={status.value} value={status.value}>
                {t(status.label_key)}
              </option>
            ))}
          </Select>
        </Field>

        {meta !== undefined && meta.companies.length > 0 ? (
          <Field label={t('col_company')}>
            <Select
              value={state.company}
              onChange={(event) => update((current) => applyFilters(current, { company: event.target.value }))}
            >
              <option value="">{t('all')}</option>
              {meta.companies.map((company) => (
                <option key={company} value={company}>
                  {company}
                </option>
              ))}
            </Select>
          </Field>
        ) : null}

        <Field label={t('col_byod')}>
          <Select
            value={state.byod}
            onChange={(event) =>
              update((current) => applyFilters(current, { byod: event.target.value as ByodFilter }))
            }
          >
            <option value="">{t('all')}</option>
            <option value="true">{t('yes')}</option>
            <option value="false">{t('no')}</option>
          </Select>
        </Field>

        <Checkbox
          className="pb-2"
          label={t('ui_trashed')}
          checked={state.trashed}
          onChange={(event) => update((current) => applyFilters(current, { trashed: event.target.checked }))}
        />

        <div className="flex flex-wrap items-center gap-2 pb-0.5">
          <Button variant="ghost" disabled={!hasActiveFilters(state)} onClick={reset}>
            {t('ui_clear_filters')}
          </Button>
          <a className={EXPORT_LINK_CLASS} href={deviceExportUrl(state)} download>
            {t('export_btn')}
          </a>
        </div>
      </div>

      <DataGrid
        aria-label={t('ui_devices')}
        columns={columns}
        rows={rows}
        isLoading={isFirstLoad}
        emptyLabel={t('no_assets')}
        sort={{ column: state.sort === '' ? null : state.sort, dir: sortedDir(state, state.sort) }}
        onSort={onSort}
        rowId={(row) => row.id}
        onRowClick={(row) => {
          void navigate({ to: '/devices/$deviceId', params: { deviceId: String(row.id) } });
        }}
      />

      <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
        <p className="m-0 text-[var(--color-muted)]">{t('ui_showing_range', { from, to, total })}</p>
        <div className="flex flex-wrap items-center gap-2">
          <Field label={t('ui_rows_per_page')}>
            <Select
              value={String(state.perPage)}
              onChange={(event) =>
                update((current) => ({ ...current, perPage: Number(event.target.value), page: 1 }))
              }
            >
              {pageSizeOptions.map((size) => (
                <option key={size} value={size}>
                  {size}
                </option>
              ))}
            </Select>
          </Field>
          <Button
            disabled={page <= 1}
            onClick={() => update((current) => ({ ...current, page: Math.max(1, current.page - 1) }))}
          >
            {t('previous')}
          </Button>
          <span className="whitespace-nowrap text-[var(--color-muted)]">
            {t('page')} {page} {t('of')} {pages}
          </span>
          <Button
            disabled={page >= pages}
            onClick={() => update((current) => ({ ...current, page: Math.min(pages, current.page + 1) }))}
          >
            {t('next')}
          </Button>
        </div>
      </div>
    </section>
  );
}
