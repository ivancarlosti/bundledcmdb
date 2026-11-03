import {
  flexRender,
  getCoreRowModel,
  useReactTable,
  type ColumnDef,
  type SortingState,
} from '@tanstack/react-table';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';

import { cn } from '@/lib/cn';

import type { SortDir } from './gridSearch';

export type DataGridProps<T> = {
  columns: ColumnDef<T, unknown>[];
  rows: T[];
  /** Column the server is currently sorting by (null when the default is used). */
  sort: { column: string | null; dir: SortDir | null };
  onSort: (columnId: string) => void;
  isLoading?: boolean;
  emptyLabel: string;
  rowId: (row: T) => string | number;
  onRowClick?: (row: T) => void;
  rowLabel?: (row: T) => string;
  'aria-label'?: string;
};

function SortIcon({ dir }: { dir: SortDir | null }) {
  if (dir === 'asc') {
    return <ArrowUp aria-hidden="true" className="size-3.5" />;
  }
  if (dir === 'desc') {
    return <ArrowDown aria-hidden="true" className="size-3.5" />;
  }
  return <ArrowUpDown aria-hidden="true" className="size-3.5 opacity-40" />;
}


/**
 * Headless table (TanStack Table v8) rendered with the panel's tokens.
 *
 * Sorting is `manual`: the browser never reorders rows, it only reports the
 * column click and the API does the ordering (that is what makes sorting work on
 * every page, not just the rows currently loaded — the 2279453 regression).
 */
export function DataGrid<T>({
  columns,
  rows,
  sort,
  onSort,
  isLoading = false,
  emptyLabel,
  rowId,
  onRowClick,
  rowLabel,
  'aria-label': ariaLabel,
}: DataGridProps<T>) {
  const sorting: SortingState =
    sort.column !== null && sort.dir !== null ? [{ id: sort.column, desc: sort.dir === 'desc' }] : [];

  const table = useReactTable({
    data: rows,
    columns,
    state: { sorting },
    manualSorting: true,
    getCoreRowModel: getCoreRowModel(),
    getRowId: (row) => String(rowId(row)),
  });

  const headerGroups = table.getHeaderGroups();
  const columnCount = table.getAllLeafColumns().length;

  return (
    <div className="card overflow-hidden">
      <div className="max-h-[calc(100dvh-18rem)] overflow-auto" aria-busy={isLoading}>
        <table aria-label={ariaLabel} className="w-full border-collapse text-sm">
          <thead className="sticky top-0 z-10">
            {headerGroups.map((headerGroup) => (
              <tr key={headerGroup.id} className="bg-[var(--color-surface-2)]">
                {headerGroup.headers.map((header) => {
                  const align = (header.column.columnDef.meta as { align?: 'start' | 'end' } | undefined)?.align;
                  const canSort = header.column.getCanSort();
                  const dir = sort.column === header.column.id ? sort.dir : null;
                  const ariaSort = dir === 'asc' ? 'ascending' : dir === 'desc' ? 'descending' : 'none';

                  return (
                    <th
                      key={header.id}
                      scope="col"
                      aria-sort={ariaSort}
                      className={cn(
                        'border-b border-[var(--color-border)] px-3 py-2 font-medium whitespace-nowrap text-[var(--color-muted)]',
                        align === 'end' ? 'text-end' : 'text-start',
                      )}
                    >
                      {header.isPlaceholder ? null : canSort ? (
                        <button
                          type="button"
                          onClick={() => onSort(header.column.id)}
                          className={cn(
                            'inline-flex items-center gap-1 rounded-[var(--radius-sm)] px-1 py-0.5 hover:text-[var(--color-text)]',
                            dir !== null && 'text-[var(--color-text)]',
                          )}
                          data-sort-column={header.column.id}
                        >
                          {flexRender(header.column.columnDef.header, header.getContext())}
                          <SortIcon dir={dir} />
                        </button>
                      ) : (
                        flexRender(header.column.columnDef.header, header.getContext())
                      )}
                    </th>
                  );
                })}
              </tr>
            ))}
          </thead>
          <tbody>
            {isLoading && rows.length === 0
              ? Array.from({ length: 8 }, (_, index) => (
                  <tr key={`skeleton-${index}`} className="border-b border-[var(--color-border)]">
                    {headerGroups[0]?.headers.map((header) => (
                      <td key={header.id} className="px-3 py-3">
                        <span className="block h-4 w-full animate-pulse rounded-[var(--radius-sm)] bg-[var(--color-surface-3)]" />
                      </td>
                    ))}
                  </tr>
                ))
              : null}

            {!isLoading && rows.length === 0 ? (
              <tr>
                <td colSpan={columnCount} className="px-3 py-12 text-center text-sm text-[var(--color-muted)]">
                  {emptyLabel}
                </td>
              </tr>
            ) : null}

            {table.getRowModel().rows.map((row) => (
              <tr
                key={row.id}
                className={cn(
                  'border-b border-[var(--color-border)] last:border-b-0',
                  onRowClick && 'cursor-pointer hover:bg-[var(--color-surface-2)] focus-visible:bg-[var(--color-surface-2)]',
                )}
                tabIndex={onRowClick ? 0 : undefined}
                aria-label={rowLabel?.(row.original)}
                onClick={onRowClick ? () => onRowClick(row.original) : undefined}
                onKeyDown={
                  onRowClick
                    ? (event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                          event.preventDefault();
                          onRowClick(row.original);
                        }
                      }
                    : undefined
                }
                data-testid="data-row"
              >
                {row.getVisibleCells().map((cell) => {
                  const align = (cell.column.columnDef.meta as { align?: 'start' | 'end' } | undefined)?.align;
                  return (
                    <td
                      key={cell.id}
                      className={cn('px-3 py-2 align-middle', align === 'end' ? 'text-end' : 'text-start')}
                    >
                      {flexRender(cell.column.columnDef.cell, cell.getContext())}
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
