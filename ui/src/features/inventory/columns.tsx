/**
 * Column model of the Assets/Systems grids.
 *
 * The catalogue is small and fixed (`inventory_column_defs()`), so unlike the
 * device grid it is declared here instead of coming from `/meta`: the server only
 * publishes the *set* of columns through `not_applicable` validation. Each entry
 * names the column, its `col_item_*` header key and how to render it.
 */

import type { TFunction } from 'i18next';
import type { LegacyColumnDef as ColumnDef } from '@tanstack/react-table/legacy';

import { StatusPill } from '@/features/devices/StatusPill';
import { formatDate } from '@/lib/format';

import type { InventoryKind, InventoryRow } from './api';

export type InventoryCellType = 'text' | 'status' | 'date' | 'users';

export type InventoryColumn = {
  key: keyof InventoryRow & string;
  labelKey: string;
  type: InventoryCellType;
  /** Assignment is a join table, so it has no column to ORDER BY. */
  sortable: boolean;
};

/** Header keys shared by both kinds. */
const LABELS: Record<string, string> = {
  name: 'col_item_name',
  brand: 'col_item_brand',
  category: 'col_item_category',
  model: 'col_item_model',
  version: 'col_item_version',
  serial: 'col_item_serial',
  location: 'col_item_location',
  status: 'col_status',
  users: 'col_item_users',
  purchase_date: 'col_purchase_date',
  warranty_end: 'col_warranty',
  license_expiry: 'col_item_license_expiry',
  updated_at: 'col_updated_at',
};

/**
 * Columns of one tab, in grid order.
 *
 * Assets get model/serial/location and the warranty date; systems get the version
 * and the licence expiry. Everything else is shared.
 */
export function inventoryColumns(kind: InventoryKind): InventoryColumn[] {
  const columns: InventoryColumn[] = [
    { key: 'name', labelKey: LABELS.name as string, type: 'text', sortable: true },
    { key: 'brand', labelKey: LABELS.brand as string, type: 'text', sortable: true },
    { key: 'category', labelKey: LABELS.category as string, type: 'text', sortable: true },
  ];

  if (kind === 'asset') {
    columns.push(
      { key: 'model', labelKey: LABELS.model as string, type: 'text', sortable: true },
      { key: 'serial', labelKey: LABELS.serial as string, type: 'text', sortable: true },
      { key: 'location', labelKey: LABELS.location as string, type: 'text', sortable: true },
    );
  } else {
    columns.push({ key: 'version', labelKey: LABELS.version as string, type: 'text', sortable: true });
  }

  columns.push(
    { key: 'status', labelKey: LABELS.status as string, type: 'status', sortable: true },
    { key: 'users', labelKey: LABELS.users as string, type: 'users', sortable: false },
    { key: 'purchase_date', labelKey: LABELS.purchase_date as string, type: 'date', sortable: true },
    kind === 'asset'
      ? { key: 'warranty_end', labelKey: LABELS.warranty_end as string, type: 'date', sortable: true }
      : { key: 'license_expiry', labelKey: LABELS.license_expiry as string, type: 'date', sortable: true },
    { key: 'updated_at', labelKey: LABELS.updated_at as string, type: 'date', sortable: true },
  );

  return columns;
}

/** "Common usage" pill, used by the grid cell and the detail page. */
export function CommonUsageBadge({ label }: { label: string }) {
  return (
    <span className="inline-flex items-center rounded-full bg-[color-mix(in_oklab,var(--color-accent)_16%,transparent)] px-2 py-0.5 text-xs font-medium whitespace-nowrap text-[var(--color-accent)]">
      {label}
    </span>
  );
}

function Empty() {
  return <span className="text-[var(--color-muted)]">—</span>;
}

/** One cell. `users` renders the shared/common-usage state instead of a list. */
export function InventoryCell({
  row,
  column,
  t,
  locale,
  statusLabels,
}: {
  row: InventoryRow;
  column: InventoryColumn;
  t: TFunction;
  locale: string;
  statusLabels: Map<string, string>;
}) {
  const raw = row[column.key];

  if (column.type === 'users') {
    if (row.common_usage) {
      return <CommonUsageBadge label={t('ui_common_usage')} />;
    }
    if (row.users.length === 0) {
      return <Empty />;
    }
    const [first, ...rest] = row.users;
    return (
      <span className="block max-w-[26ch] truncate" title={row.users.join(', ')}>
        {first}
        {rest.length > 0 ? ` +${rest.length}` : ''}
      </span>
    );
  }

  if (raw === null || raw === undefined || raw === '') {
    return <Empty />;
  }

  if (column.type === 'status') {
    const status = String(raw);
    return <StatusPill status={status} label={statusLabels.get(status) ?? status} />;
  }

  if (column.type === 'date') {
    return <span className="whitespace-nowrap">{formatDate(raw, locale)}</span>;
  }

  const text = String(raw);
  return (
    <span className="block max-w-[26ch] truncate" title={text}>
      {text}
    </span>
  );
}

/** TanStack Table model for the grid. */
export function buildInventoryColumns(
  kind: InventoryKind,
  t: TFunction,
  locale: string,
  statuses: { value: string; label_key: string }[],
): ColumnDef<InventoryRow, unknown>[] {
  const statusLabels = new Map(statuses.map((status) => [status.value, t(status.label_key)]));

  return inventoryColumns(kind).map((column) => ({
    id: column.key,
    accessorFn: (row) => row[column.key],
    header: t(column.labelKey),
    enableSorting: column.sortable,
    cell: ({ row }) => (
      <InventoryCell row={row.original} column={column} t={t} locale={locale} statusLabels={statusLabels} />
    ),
  }));
}
