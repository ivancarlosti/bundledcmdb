import type { TFunction } from 'i18next';
import type { LegacyColumnDef as ColumnDef } from '@tanstack/react-table/legacy';

import { formatDate, formatDateTime, formatNumber } from '@/lib/format';

import type { DeviceRow, MetaPayload } from './api';
import { StatusPill } from './StatusPill';

export type GridColumn = ColumnDef<DeviceRow, unknown>;

/**
 * Read one cell value from a row.
 *
 * Asset columns are read through the `api_key` published by `/meta` (the wire
 * format is snake_case while the schema keeps the legacy PascalCase names, and
 * the virtual `files` column maps to `files_count`). Custom fields live under
 * `custom`.
 */
export function cellValue(row: DeviceRow, column: { key: string; api_key?: string }): unknown {
  if (column.key.startsWith('custom:')) {
    return row.custom[column.key.slice('custom:'.length)] ?? null;
  }
  return (row as unknown as Record<string, unknown>)[column.api_key ?? ''] ?? null;
}

type CellProps = {
  value: unknown;
  columnKey: string;
  type: string;
  locale: string;
  t: TFunction;
  statusLabels: Map<string, string>;
  sourceColors: Map<string, string | null>;
};

function Empty() {
  return <span className="text-[var(--color-muted)]">—</span>;
}

/** One grid cell, rendered by the column type published in `/meta`. */
export function GridCell({ value, columnKey, type, locale, t, statusLabels, sourceColors }: CellProps) {
  if (value === null || value === undefined || value === '') {
    return <Empty />;
  }

  if (columnKey === 'status' || type === 'status') {
    const status = String(value);
    return <StatusPill status={status} label={statusLabels.get(status) ?? status} />;
  }

  if (columnKey === 'source') {
    const slug = String(value);
    const color = sourceColors.get(slug);
    return (
      <span className="inline-flex items-center gap-1.5">
        <span
          aria-hidden="true"
          className="size-2 shrink-0 rounded-full bg-[var(--color-border)]"
          style={color ? { backgroundColor: color } : undefined}
        />
        <span>{slug}</span>
      </span>
    );
  }

  if (type === 'bool') {
    return <span>{value === true || value === 1 || value === '1' ? t('yes') : t('no')}</span>;
  }

  if (columnKey === 'files') {
    return <span className="tabular-nums">{formatNumber(value, locale)}</span>;
  }

  if (type === 'int') {
    return <span className="tabular-nums">{formatNumber(value, locale)}</span>;
  }

  if (type === 'datetime') {
    return <span className="whitespace-nowrap">{formatDateTime(value, locale)}</span>;
  }

  if (type === 'date') {
    return <span className="whitespace-nowrap">{formatDate(value, locale)}</span>;
  }

  const text = String(value);
  return (
    <span className="block max-w-[26ch] truncate" title={text}>
      {text}
    </span>
  );
}

/** Inline editing of a cell (Status and boolean columns) inside the grid. */
export type InlineEditOptions = {
  /** True while a write is in flight — the controls are disabled meanwhile. */
  busy: boolean;
  onEdit: (row: DeviceRow, key: string, value: string | boolean) => void;
};

function InlineSelect({
  label,
  value,
  options,
  disabled,
  onChange,
}: {
  label: string;
  value: string;
  options: { value: string; label: string }[];
  disabled: boolean;
  onChange: (value: string) => void;
}) {
  const known = options.some((option) => option.value === value);
  return (
    <select
      aria-label={label}
      className="h-7 max-w-[12rem] rounded-[var(--radius-sm)] border border-[var(--color-border)] bg-[var(--color-surface)] px-1 text-xs text-[var(--color-text)]"
      value={value}
      disabled={disabled}
      // The row itself is a click target (it opens the detail page); editing a
      // cell must not navigate away or activate the row from the keyboard.
      onClick={(event) => event.stopPropagation()}
      onKeyDown={(event) => event.stopPropagation()}
      onChange={(event) => {
        event.stopPropagation();
        onChange(event.target.value);
      }}
    >
      {known ? null : <option value={value}>{value}</option>}
      {options.map((option) => (
        <option key={option.value} value={option.value}>
          {option.label}
        </option>
      ))}
    </select>
  );
}

/**
 * Build the TanStack Table column model from `/meta`.
 *
 * Nothing here hard-codes a column name: adding an asset column or a custom field
 * to the server (and marking it `grid`) makes it appear, sortable, in the SPA.
 */
export function cellContext(meta: MetaPayload, t: TFunction, locale: string) {
  return {
    locale,
    t,
    statusLabels: new Map(meta.statuses.map((status) => [status.value, t(status.label_key)])),
    sourceColors: new Map(meta.sources.map((source) => [source.slug, source.color])),
  };
}

export function buildColumns(
  meta: MetaPayload,
  t: TFunction,
  locale: string,
  inline?: InlineEditOptions,
): GridColumn[] {
  const context = cellContext(meta, t, locale);
  const columns: GridColumn[] = [];

  for (const column of meta.columns) {
    if (!column.in_grid) {
      continue;
    }

    const editable = inline !== undefined && column.editable && !column.virtual;
    const label = t(column.label_key, { defaultValue: column.label });

    columns.push({
      id: column.key,
      accessorFn: (row) => cellValue(row, column),
      header: label,
      enableSorting: column.sortable,
      cell: ({ getValue, row }) => {
        if (!editable) {
          return <GridCell value={getValue()} columnKey={column.key} type={column.type} {...context} />;
        }

        if (column.key === 'Status') {
          const value = typeof getValue() === 'string' ? (getValue() as string) : '';
          return (
            <InlineSelect
              label={label}
              value={value}
              disabled={inline.busy}
              options={meta.statuses.map((status) => ({
                value: status.value,
                label: t(status.label_key),
              }))}
              onChange={(next) => inline.onEdit(row.original, column.api_key, next)}
            />
          );
        }

        if (column.type === 'bool') {
          const current = getValue();
          return (
            <InlineSelect
              label={label}
              value={current === true || current === 1 || current === '1' ? '1' : '0'}
              disabled={inline.busy}
              options={[
                { value: '1', label: t('yes') },
                { value: '0', label: t('no') },
              ]}
              onChange={(next) => inline.onEdit(row.original, column.api_key, next === '1')}
            />
          );
        }

        return <GridCell value={getValue()} columnKey={column.key} type={column.type} {...context} />;
      },
      meta: { align: column.type === 'int' ? 'end' : 'start' },
    });
  }

  for (const field of meta.fields) {
    if (!field.in_grid) {
      continue;
    }
    const key = `custom:${field.key}`;
    columns.push({
      id: key,
      accessorFn: (row) => cellValue(row, { key }),
      header: field.label_i18n?.[locale] ?? field.label,
      enableSorting: true,
      cell: ({ getValue }) => <GridCell value={getValue()} columnKey={key} type={field.type} {...context} />,
      meta: { align: field.type === 'int' ? 'end' : 'start' },
    });
  }

  return columns;
}
