import { Link, useNavigate } from '@tanstack/react-router';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Alert, Button, Checkbox, Field, Input, Select, Spinner } from '@/components/ui';
import { useCurrentSession } from '@/features/auth/SessionContext';
import { ColumnPicker } from '@/features/grid/ColumnPicker';
import { applyColumnLayout, effectiveLayout, resolveOrder, resolveVisible } from '@/features/grid/columnLayout';
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
import { selectionState, toggleAllOnPage, toggleSelection } from '@/features/grid/selection';
import { useGridState } from '@/features/grid/useGridState';
import { usePreferences } from '@/features/preferences/usePreferences';
import { SavedViewsMenu } from '@/features/views/SavedViewsMenu';
import type { SavedViewState } from '@/features/views/api';

import { deviceExportUrl, type BulkOp, type DeviceRow, type DeviceWriteBody } from './api';
import { BulkActions } from './BulkActions';
import { buildColumns } from './columns';
import { useBulkDeviceAction, useInlineDeviceUpdate } from './useDeviceMutations';
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

  // The table is in the page flow (the page scrolls, not the table), so a new
  // page of rows would otherwise start wherever the previous one ended. Bring
  // the toolbar back into view when the page number changes — but never on the
  // first render, where the browser has already restored the scroll position.
  const sectionRef = useRef<HTMLElement>(null);
  const lastPage = useRef(state.page);
  useEffect(() => {
    if (lastPage.current === state.page) {
      return;
    }
    lastPage.current = state.page;
    sectionRef.current?.scrollIntoView?.({ block: 'start' });
  }, [state.page]);

  const inlineUpdate = useInlineDeviceUpdate();
  const preferences = usePreferences();
  const canCreate = session.capabilities.permissions['devices.create'] === true;
  const canWrite = session.capabilities.permissions['devices.update'] === true;
  const canBulk = session.capabilities.permissions['devices.bulk'] === true;
  const canDelete = session.capabilities.permissions['devices.delete'] === true;
  const canRestore = session.capabilities.permissions['devices.restore'] === true;

  // Ids ticked in the grid. Deliberately kept across pages (a bulk action on
  // "everything I selected" is the point) and cleared only when the filters are
  // reset or the action succeeded.
  const [selected, setSelected] = useState<number[]>([]);
  const bulk = useBulkDeviceAction();

  const onInlineEdit = useCallback(
    (row: DeviceRow, key: string, value: string | boolean) => {
      const body: DeviceWriteBody = { [key]: value };
      if (row.updated_at !== null) {
        body.expected_updated_at = row.updated_at;
      }
      inlineUpdate.mutate({ id: row.id, body });
    },
    [inlineUpdate],
  );

  const allColumns = useMemo(
    () =>
      metaQuery.data === undefined
        ? []
        : buildColumns(
            metaQuery.data,
            t,
            locale,
            canWrite ? { busy: inlineUpdate.isPending, onEdit: onInlineEdit } : undefined,
          ),
    [metaQuery.data, t, locale, canWrite, inlineUpdate.isPending, onInlineEdit],
  );

  const columnIds = useMemo(() => allColumns.map((column) => String(column.id)), [allColumns]);
  // A locked layout is the admin's decision for everyone: the stored per-user
  // layout is ignored for order *and* visibility, and the picker is disabled.
  const locked = metaQuery.data?.gridLayout?.locked === true;
  const layout = effectiveLayout(locked, session.gridState);
  const order = useMemo(() => resolveOrder(columnIds, layout), [columnIds, layout]);
  const visible = useMemo(() => resolveVisible(columnIds, layout), [columnIds, layout]);
  const columns = useMemo(() => applyColumnLayout(allColumns, { order, visible }), [allColumns, order, visible]);

  const pickerItems = useMemo(
    () =>
      order.map((id) => ({
        id,
        label: String(allColumns.find((column) => String(column.id) === id)?.header ?? id),
      })),
    [order, allColumns],
  );

  const onLayoutChange = (next: { order: string[]; visible: string[] }) => {
    if (locked) {
      return;
    }
    preferences.mutate({ grid_state: { order: next.order, visible: next.visible } });
  };

  // What a saved view captures: the filters/sort the URL carries plus the column
  // layout, which is the only part that lives in the preferences API.
  const currentViewState: SavedViewState = {
    q: state.q,
    source: state.source,
    status: state.status,
    company: state.company,
    sort: state.sort,
    dir: state.dir === '' ? undefined : state.dir,
    trashed: state.trashed || undefined,
    order,
    visible,
  };

  const onApplyView = (saved: SavedViewState) => {
    setSearch(saved.q ?? '');
    update((current) => ({
      ...current,
      q: saved.q ?? '',
      source: saved.source ?? '',
      status: saved.status ?? '',
      company: saved.company ?? '',
      byod: current.byod,
      trashed: saved.trashed === true,
      sort: saved.sort ?? '',
      dir: saved.dir ?? '',
      page: 1,
    }));
    if (!locked && (saved.order !== undefined || saved.visible !== undefined)) {
      preferences.mutate({ grid_state: { order: saved.order ?? order, visible: saved.visible ?? visible } });
    }
  };
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

  const pageIds = useMemo(() => rows.map((row) => row.id), [rows]);
  const pageSelection = selectionState(selected, pageIds);

  const statusOptions = useMemo(
    () => (meta?.statuses ?? []).map((status) => ({ value: status.value, label: t(status.label_key) })),
    [meta?.statuses, t],
  );

  const deleteReasonOptions = useMemo(
    () => (meta?.deleteReasons ?? []).map((option) => ({ value: option.value, label: t(option.label_key) })),
    [meta?.deleteReasons, t],
  );

  /**
   * One bulk request per action; the server answers per row, so a stale or
   * deleted row no longer swallows the whole action. The selection is cleared
   * only after the response, so a failed request can be retried as-is.
   */
  const runBulk = (op: BulkOp, value?: string | boolean, reason?: string | null) => {
    if (selected.length === 0) {
      return;
    }
    bulk.mutate(
      { op, ids: selected, value, reason },
      {
        onSuccess: () => {
          setSelected([]);
        },
      },
    );
  };

  const clearSelection = () => {
    setSelected([]);
  };

  const clearFilters = () => {
    setSelected([]);
    reset();
  };

  return (
    <section ref={sectionRef} className="flex scroll-mt-24 flex-col gap-3">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <h1 className="m-0 text-xl font-semibold">{t('ui_devices')}</h1>
          {devicesQuery.isFetching ? <Spinner className="size-4" /> : null}
          <span className="rounded-full bg-[var(--color-surface-3)] px-2 py-0.5 text-xs text-[var(--color-muted)]">
            {t('records')}: {total}
          </span>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {canCreate ? (
            <Link
              to="/devices/new"
              className="inline-flex items-center justify-center rounded-[var(--radius-md)] border border-transparent bg-[var(--color-accent)] px-3 py-1.5 text-sm font-medium text-[var(--color-accent-contrast)] hover:opacity-90"
            >
              {t('ui_new_device')}
            </Link>
          ) : null}
          <span className="text-xs text-[var(--color-muted)]">
            {t('signed_in_as')} {session.user.email}
          </span>
        </div>
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
            onChange={(event) => update((current) => applyFilters(current, { byod: event.target.value as ByodFilter }))}
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
          <Button variant="ghost" disabled={!hasActiveFilters(state)} onClick={clearFilters}>
            {t('ui_clear_filters')}
          </Button>
          <a className={EXPORT_LINK_CLASS} href={deviceExportUrl(state, 'csv')} download>
            {t('export_btn')}
          </a>
          <a className={EXPORT_LINK_CLASS} href={deviceExportUrl(state, 'xlsx')} download>
            {t('export_xlsx_btn')}
          </a>
          <SavedViewsMenu currentState={currentViewState} onApply={onApplyView} />
          {locked ? <span className="text-xs text-[var(--color-muted)]">{t('ui_columns_locked')}</span> : null}
          <ColumnPicker
            items={pickerItems}
            order={order}
            visible={visible}
            disabled={locked || preferences.isPending}
            onChange={onLayoutChange}
          />
        </div>
      </div>

      {selected.length > 0 && canBulk ? (
        <BulkActions
          count={selected.length}
          statuses={statusOptions}
          deleteReasons={deleteReasonOptions}
          canDelete={canDelete}
          canRestore={canRestore}
          pending={bulk.isPending}
          onStatus={(status) => runBulk('status', status)}
          onByod={(value) => runBulk('byod', value)}
          onDelete={(reason) => runBulk('delete', undefined, reason)}
          onRestore={() => runBulk('restore')}
          onClear={clearSelection}
        />
      ) : null}

      <p className="sr-only" role="status" aria-live="polite">
        {t('ui_showing_range', { from, to, total })}
      </p>

      <DataGrid
        aria-label={t('ui_devices')}
        columns={columns}
        rows={rows}
        isLoading={isFirstLoad}
        emptyLabel={t('no_assets')}
        sort={{ column: state.sort === '' ? null : state.sort, dir: sortedDir(state, state.sort) }}
        onSort={onSort}
        rowId={(row) => row.id}
        selection={
          canBulk
            ? {
                state: pageSelection,
                isSelected: (row) => selected.includes(row.id),
                onToggleRow: (row, checked) => setSelected((current) => toggleSelection(current, row.id, checked)),
                onToggleAll: (checked) => setSelected((current) => toggleAllOnPage(current, pageIds, checked)),
                rowLabel: (row) => t('ui_select_row', { name: row.sn ?? row.hostname ?? String(row.id) }),
                allLabel: t('ui_select_all'),
              }
            : undefined
        }
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
              onChange={(event) => update((current) => ({ ...current, perPage: Number(event.target.value), page: 1 }))}
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
