import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ColumnDef } from '@tanstack/react-table';
import { describe, expect, it, vi } from 'vitest';

import { DataGrid } from './DataGrid';

type Device = { id: number; sn: string; status: string };

const columns: ColumnDef<Device, unknown>[] = [
  {
    id: 'sn',
    accessorFn: (row) => row.sn,
    header: 'Serial number',
    enableSorting: true,
    cell: (context) => String(context.getValue()),
  },
  {
    id: 'status',
    accessorFn: (row) => row.status,
    header: 'Status',
    enableSorting: false,
    cell: (context) => String(context.getValue()),
  },
];

const rows: Device[] = [
  { id: 1, sn: 'SN-1', status: 'In Use' },
  { id: 2, sn: 'SN-2', status: 'In Stock' },
];

function renderGrid(overrides: Partial<React.ComponentProps<typeof DataGrid<Device>>> = {}) {
  return render(
    <DataGrid
      columns={columns}
      rows={rows}
      sort={{ column: null, dir: null }}
      onSort={() => {}}
      emptyLabel="No assets found."
      rowId={(row) => row.id}
      {...overrides}
    />,
  );
}

describe('DataGrid', () => {
  it('only exposes sortable headers as buttons and reports the click', async () => {
    const onSort = vi.fn();
    renderGrid({ onSort });

    await userEvent.click(screen.getByRole('button', { name: /Serial number/ }));
    expect(onSort).toHaveBeenCalledWith('sn');
    expect(screen.queryByRole('button', { name: /Status/ })).toBeNull();
  });

  it('reflects the server-side sort through aria-sort', () => {
    renderGrid({ sort: { column: 'sn', dir: 'asc' } });
    expect(screen.getByRole('columnheader', { name: /Serial number/ })).toHaveAttribute('aria-sort', 'ascending');
    expect(screen.getByRole('columnheader', { name: /Status/ })).toHaveAttribute('aria-sort', 'none');
  });

  it('shows the empty label instead of a blank table', () => {
    renderGrid({ rows: [] });
    expect(screen.getByText('No assets found.')).toBeInTheDocument();
  });

  it('activates a row from the keyboard', async () => {
    const onRowClick = vi.fn();
    renderGrid({ onRowClick });

    const firstRow = screen.getAllByTestId('data-row')[0];
    firstRow.focus();
    await userEvent.keyboard('{Enter}');
    expect(onRowClick).toHaveBeenCalledWith(rows[0]);
  });
});
