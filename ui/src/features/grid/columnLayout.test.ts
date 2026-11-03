import { describe, expect, it } from 'vitest';

import {
  applyColumnLayout,
  moveColumn,
  resolveOrder,
  resolveVisible,
  toggleColumn,
} from './columnLayout';

const columns = [{ id: 'sn' }, { id: 'hostname' }, { id: 'status' }, { id: 'files' }];
const ids = columns.map((column) => column.id);

describe('applyColumnLayout', () => {
  it('keeps the published order without a stored layout', () => {
    expect(applyColumnLayout(columns, null).map((column) => column.id)).toEqual(ids);
  });

  it('reorders to the stored order and appends new columns at the end', () => {
    const result = applyColumnLayout(columns, { order: ['status', 'sn'] });
    expect(result.map((column) => column.id)).toEqual(['status', 'sn', 'hostname', 'files']);
  });

  it('drops columns the server no longer publishes', () => {
    const result = applyColumnLayout(columns, { order: ['gone', 'hostname'], visible: ['gone', 'hostname'] });
    expect(result.map((column) => column.id)).toEqual(['hostname']);
  });

  it('hides the columns that are not visible', () => {
    const result = applyColumnLayout(columns, { visible: ['sn', 'status'] });
    expect(result.map((column) => column.id)).toEqual(['sn', 'status']);
  });

  it('treats an empty visible list as "show everything"', () => {
    expect(applyColumnLayout(columns, { visible: [] })).toHaveLength(columns.length);
  });
});

describe('resolveOrder / resolveVisible', () => {
  it('appends columns missing from the stored order', () => {
    expect(resolveOrder(ids, { order: ['files'] })).toEqual(['files', 'sn', 'hostname', 'status']);
  });

  it('falls back to every column when nothing is stored', () => {
    expect(resolveVisible(ids, null)).toEqual(ids);
  });

  it('ignores unknown ids in the stored visibility', () => {
    expect(resolveVisible(ids, { visible: ['sn', 'nope'] })).toEqual(['sn']);
  });
});

describe('moveColumn', () => {
  it('moves a column up and down', () => {
    expect(moveColumn(ids, 'status', -1)).toEqual(['sn', 'status', 'hostname', 'files']);
    expect(moveColumn(ids, 'status', 1)).toEqual(['sn', 'hostname', 'files', 'status']);
  });

  it('is a no-op at the edges and for unknown ids', () => {
    expect(moveColumn(ids, 'sn', -1)).toEqual(ids);
    expect(moveColumn(ids, 'files', 1)).toEqual(ids);
    expect(moveColumn(ids, 'nope', 1)).toEqual(ids);
  });
});

describe('toggleColumn', () => {
  it('hides and shows a column', () => {
    expect(toggleColumn(['sn', 'status'], 'status')).toEqual(['sn']);
    expect(toggleColumn(['sn'], 'hostname')).toEqual(['sn', 'hostname']);
  });

  it('refuses to hide the last visible column', () => {
    expect(toggleColumn(['sn'], 'sn')).toEqual(['sn']);
  });
});
