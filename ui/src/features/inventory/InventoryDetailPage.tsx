/**
 * Read-only view of one asset or system.
 *
 * The row is a definition list built from the same column model as the grid, so a
 * value is formatted exactly like it is in the list (status pill, dates, the
 * common-usage badge). Editing is a link: the form is the only write path.
 */

import { Link, useParams } from '@tanstack/react-router';
import { useTranslation } from 'react-i18next';

import { Alert, Spinner } from '@/components/ui';
import { useCurrentSession } from '@/features/auth/SessionContext';

import { inventoryEditKey, inventoryListKey, inventoryRoute, type InventoryKind } from './api';
import { CommonUsageBadge, InventoryCell, inventoryColumns } from './columns';
import { useInventoryItem, useInventoryMeta } from './useInventory';

export function InventoryDetailPage({ kind }: { kind: InventoryKind }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.resolvedLanguage ?? 'en-US';
  const params = useParams({ strict: false }) as { itemId?: string };
  const session = useCurrentSession();

  const id = params.itemId !== undefined && /^\d+$/.test(params.itemId) ? Number(params.itemId) : null;
  const itemQuery = useInventoryItem(kind, id);
  const metaQuery = useInventoryMeta();

  const listPath = inventoryRoute(kind);
  const canEdit = session.capabilities.permissions[`${kind}s.update`] === true;

  if (id === null || itemQuery.isError) {
    return (
      <Alert
        tone="danger"
        title={t('ui_inventory_not_found')}
        action={
          <Link to={listPath} className="text-sm text-[var(--color-accent)]">
            {t('back_to_list')}
          </Link>
        }
      />
    );
  }

  if (itemQuery.isPending || itemQuery.data === undefined) {
    return (
      <div className="flex items-center gap-2 text-sm text-[var(--color-muted)]">
        <Spinner className="size-4" /> {t('ui_loading')}
      </div>
    );
  }

  const row = itemQuery.data;
  const statusLabels = new Map((metaQuery.data?.statuses ?? []).map((status) => [status.value, t(status.label_key)]));
  const columns = inventoryColumns(kind).filter((column) => column.key !== 'updated_at');

  return (
    <section className="mx-auto flex w-full max-w-3xl flex-col gap-3">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap items-center gap-2">
          <h1 className="m-0 text-xl font-semibold">{row.name ?? t(inventoryListKey(kind))}</h1>
          {row.common_usage ? <CommonUsageBadge label={t('ui_common_usage')} /> : null}
        </div>
        <div className="flex items-center gap-3">
          <Link to={listPath} className="text-sm text-[var(--color-accent)]">
            {t('back_to_list')}
          </Link>
          {canEdit ? (
            <Link
              to={`${listPath}/$itemId/edit` as '/assets/$itemId/edit'}
              params={{ itemId: String(row.id) }}
              className="inline-flex items-center justify-center rounded-[var(--radius-md)] border border-transparent bg-[var(--color-accent)] px-3 py-1.5 text-sm font-medium text-[var(--color-accent-contrast)] hover:opacity-90"
            >
              {t(inventoryEditKey(kind))}
            </Link>
          ) : null}
        </div>
      </header>

      <dl className="card m-0 grid gap-x-4 gap-y-3 p-4 sm:grid-cols-2">
        {columns.map((column) => (
          <div key={column.key} className="flex flex-col gap-0.5">
            <dt className="text-xs font-medium text-[var(--color-muted)]">{t(column.labelKey)}</dt>
            <dd className="m-0 text-sm">
              <InventoryCell row={row} column={column} t={t} locale={locale} statusLabels={statusLabels} />
            </dd>
          </div>
        ))}
        <div className="flex flex-col gap-0.5 sm:col-span-2">
          <dt className="text-xs font-medium text-[var(--color-muted)]">{t('col_company')}</dt>
          <dd className="m-0 text-sm">{row.company ?? '—'}</dd>
        </div>
      </dl>

      {row.notes === null || row.notes === '' ? null : (
        <div className="card p-4">
          <h2 className="m-0 mb-2 text-sm font-semibold">{t('col_notes')}</h2>
          <p className="m-0 whitespace-pre-wrap break-words text-sm">{row.notes}</p>
        </div>
      )}
    </section>
  );
}

/** `/assets/$itemId` */
export function AssetDetailPage() {
  return <InventoryDetailPage kind="asset" />;
}

/** `/systems/$itemId` */
export function SystemDetailPage() {
  return <InventoryDetailPage kind="system" />;
}
