/**
 * Grid of one inventory kind (Assets or Systems).
 *
 * Server-side search, filter, sort and paging, all carried in the address bar — the
 * same contract as the device grid, minus the parts that only make sense for
 * devices (sources, trash, bulk actions, column layout, saved views).
 */

import { Link, useNavigate } from '@tanstack/react-router';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Alert, Button, Field, Input, Select, Spinner } from '@/components/ui';
import { useCurrentSession } from '@/features/auth/SessionContext';
import { DataGrid } from '@/features/grid/DataGrid';

import { inventoryEmptyKey, inventoryListKey, inventoryNewKey, inventoryRoute, type InventoryKind } from './api';
import { buildInventoryColumns } from './columns';
import {
  applyInventoryFilters,
  hasInventoryFilters,
  nextInventorySort,
  useInventoryState,
  type InventoryCommonUsage,
} from './inventoryState';
import { useInventoryList, useInventoryMeta } from './useInventory';

/** Row click → detail page. Two literal routes, so the type checker stays useful. */
function openItem(navigate: ReturnType<typeof useNavigate>, kind: InventoryKind, id: number): void {
  if (kind === 'asset') {
    void navigate({ to: '/assets/$itemId', params: { itemId: String(id) } });
    return;
  }
  void navigate({ to: '/systems/$itemId', params: { itemId: String(id) } });
}

export function InventoryGridPage({ kind }: { kind: InventoryKind }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.resolvedLanguage ?? 'en-US';
  const navigate = useNavigate();
  const session = useCurrentSession();

  const canView = session.capabilities.permissions[`${kind}s.view`] === true;
  const canCreate = session.capabilities.permissions[`${kind}s.create`] === true;

  const metaQuery = useInventoryMeta();
  const { state, update, reset } = useInventoryState(session.perPage);
  const listQuery = useInventoryList(kind, state);
  const [search, setSearch] = useState(state.q);

  // Debounced search: 300 ms after typing stops the URL (and the query) follow.
  useEffect(() => {
    const trimmed = search.trim();
    if (trimmed === state.q) {
      return;
    }
    const timer = window.setTimeout(() => {
      update((current) => applyInventoryFilters(current, { q: trimmed }));
    }, 300);
    return () => window.clearTimeout(timer);
  }, [search, state.q, update]);

  const columns = useMemo(
    () => buildInventoryColumns(kind, t, locale, metaQuery.data?.statuses ?? []),
    [kind, t, locale, metaQuery.data?.statuses],
  );

  const payload = listQuery.data;
  const rows = payload?.rows ?? [];
  const total = payload?.total ?? 0;
  const page = payload?.page ?? state.page;
  const pages = payload?.pages ?? 1;
  const perPage = payload?.per_page ?? state.perPage;
  const from = total === 0 ? 0 : (page - 1) * perPage + 1;
  const to = total === 0 ? 0 : from + rows.length - 1;

  const pageSizeOptions = Array.from(
    new Set([...(session.pageSizes.length > 0 ? session.pageSizes : [10, 25, 50, 100]), state.perPage]),
  ).sort((a, b) => a - b);

  const statusOptions = metaQuery.data?.statuses ?? [];

  /**
   * Categories of this kind as the filter offers them. A value carried by the
   * current URL but missing from the registry (a legacy row, a renamed category)
   * is kept in the list so the select shows the filter it is actually applying.
   */
  const categoryOptions = Array.from(
    new Set([
      ...(metaQuery.data?.inventoryCategories ?? []).filter((entry) => entry.kind === kind).map((entry) => entry.name),
      ...(state.category === '' ? [] : [state.category]),
    ]),
  );

  if (!canView) {
    return <Alert tone="danger" title={t('ui_err_forbidden')} />;
  }

  return (
    <section className="flex flex-col gap-3">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <h1 className="m-0 text-xl font-semibold">{t(inventoryListKey(kind))}</h1>
          {listQuery.isFetching ? <Spinner className="size-4" /> : null}
          <span className="rounded-full bg-[var(--color-surface-3)] px-2 py-0.5 text-xs text-[var(--color-muted)]">
            {t('records')}: {total}
          </span>
        </div>
        {canCreate ? (
          <Link
            to={`${inventoryRoute(kind)}/new` as '/assets/new'}
            className="inline-flex items-center justify-center rounded-[var(--radius-md)] border border-transparent bg-[var(--color-accent)] px-3 py-1.5 text-sm font-medium text-[var(--color-accent-contrast)] hover:opacity-90"
          >
            {t(inventoryNewKey(kind))}
          </Link>
        ) : null}
      </header>

      {listQuery.isError ? (
        <Alert
          title={t('ui_api_unavailable')}
          action={<Button onClick={() => void listQuery.refetch()}>{t('ui_retry')}</Button>}
        >
          <p className="m-0">{listQuery.error instanceof Error ? listQuery.error.message : ''}</p>
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

        <Field label={t('col_status')}>
          <Select
            value={state.status}
            onChange={(event) => update((current) => applyInventoryFilters(current, { status: event.target.value }))}
          >
            <option value="">{t('all')}</option>
            {statusOptions.map((status) => (
              <option key={status.value} value={status.value}>
                {t(status.label_key)}
              </option>
            ))}
          </Select>
        </Field>

        <Field label={t('col_item_category')}>
          <Select
            value={state.category}
            onChange={(event) => update((current) => applyInventoryFilters(current, { category: event.target.value }))}
          >
            <option value="">{t('all')}</option>
            {categoryOptions.map((category) => (
              <option key={category} value={category}>
                {category}
              </option>
            ))}
          </Select>
        </Field>

        <Field label={t('ui_common_usage')}>
          <Select
            value={state.commonUsage}
            onChange={(event) =>
              update((current) =>
                applyInventoryFilters(current, { commonUsage: event.target.value as InventoryCommonUsage }),
              )
            }
          >
            <option value="">{t('all')}</option>
            <option value="true">{t('yes')}</option>
            <option value="false">{t('no')}</option>
          </Select>
        </Field>

        <Button variant="ghost" disabled={!hasInventoryFilters(state)} onClick={reset}>
          {t('ui_clear_filters')}
        </Button>
      </div>

      <p className="sr-only" role="status" aria-live="polite">
        {t('ui_showing_range', { from, to, total })}
      </p>

      <DataGrid
        aria-label={t(inventoryListKey(kind))}
        columns={columns}
        rows={rows}
        isLoading={listQuery.isPending}
        emptyLabel={t(inventoryEmptyKey(kind))}
        sort={{ column: state.sort === '' ? null : state.sort, dir: state.dir === '' ? null : state.dir }}
        onSort={(columnId) => update((current) => ({ ...current, ...nextInventorySort(current, columnId) }))}
        rowId={(row) => row.id}
        onRowClick={(row) => openItem(navigate, kind, row.id)}
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
            onClick={() => update((current) => ({ ...current, page: Math.max(1, page - 1) }))}
          >
            {t('previous')}
          </Button>
          <span className="whitespace-nowrap text-[var(--color-muted)]">
            {t('page')} {page} {t('of')} {pages}
          </span>
          <Button
            disabled={page >= pages}
            onClick={() => update((current) => ({ ...current, page: Math.min(pages, page + 1) }))}
          >
            {t('next')}
          </Button>
        </div>
      </div>
    </section>
  );
}

/** `/assets` — the physical inventory tab. */
export function AssetsPage() {
  return <InventoryGridPage kind="asset" />;
}

/** `/systems` — the software/service catalogue tab. */
export function SystemsPage() {
  return <InventoryGridPage kind="system" />;
}
