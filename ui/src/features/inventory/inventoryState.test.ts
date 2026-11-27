import { describe, expect, it } from 'vitest';

import {
  applyInventoryFilters,
  defaultInventoryState,
  hasInventoryFilters,
  inventoryQuery,
  inventoryQueryKey,
  inventorySearchString,
  nextInventorySort,
  parseInventorySearch,
} from './inventoryState';

describe('inventory grid state', () => {
  it('parses the address bar', () => {
    const state = parseInventorySearch(
      '?q=tv&status=In%20Use&category=Display&common_usage=true&sort=name&dir=asc&page=3&per_page=50',
      25,
    );
    expect(state).toEqual({
      q: 'tv',
      status: 'In Use',
      category: 'Display',
      commonUsage: 'true',
      sort: 'name',
      dir: 'asc',
      page: 3,
      perPage: 50,
    });
  });

  it('falls back to the defaults for junk values', () => {
    const state = parseInventorySearch('?common_usage=maybe&dir=sideways&page=-2&per_page=abc', 25);
    expect(state.commonUsage).toBe('');
    expect(state.dir).toBe('');
    expect(state.page).toBe(1);
    expect(state.perPage).toBe(25);
  });

  it('writes only what differs from the defaults', () => {
    const state = { ...defaultInventoryState(25), q: 'desk', page: 2 };
    expect(inventorySearchString(state, 25)).toBe('?q=desk&page=2');
    expect(inventorySearchString(defaultInventoryState(25), 25)).toBe('');
    // A page size that differs from the session preference must survive a reload.
    expect(inventorySearchString({ ...defaultInventoryState(25), perPage: 50 }, 25)).toBe('?per_page=50');
  });

  it('round-trips through the address bar', () => {
    const state = {
      q: 'a b',
      status: 'In Stock',
      category: '',
      commonUsage: 'false' as const,
      sort: 'brand',
      dir: 'desc' as const,
      page: 4,
      perPage: 50,
    };
    expect(parseInventorySearch(inventorySearchString(state, 25), 25)).toEqual(state);
  });

  it('builds the request query and a stable cache key', () => {
    const state = { ...defaultInventoryState(25), status: 'In Use' };
    expect(inventoryQuery(state, 'Acme')).toMatchObject({ status: 'In Use', company: 'Acme', page: 1 });
    expect(inventoryQueryKey(state, 'Acme')).toContain('status=In+Use');
    expect(inventoryQueryKey(state, 'Acme')).toBe(inventoryQueryKey({ ...state }, 'Acme'));
    expect(inventoryQueryKey(state, 'Acme')).not.toBe(inventoryQueryKey(state, 'Other'));
  });

  it('resets paging when a filter changes', () => {
    const state = { ...defaultInventoryState(25), page: 7 };
    expect(applyInventoryFilters(state, { status: 'In Stock' })).toMatchObject({ status: 'In Stock', page: 1 });
    // An explicit page (the pager) is kept.
    expect(applyInventoryFilters(state, { page: 2 })).toMatchObject({ page: 2 });
  });

  it('reports whether anything is filtered', () => {
    expect(hasInventoryFilters(defaultInventoryState(25))).toBe(false);
    expect(hasInventoryFilters({ ...defaultInventoryState(25), q: 'tv' })).toBe(true);
    expect(hasInventoryFilters({ ...defaultInventoryState(25), commonUsage: 'true' })).toBe(true);
  });

  it('cycles a column through asc → desc → asc', () => {
    const state = defaultInventoryState(25);
    expect(nextInventorySort(state, 'name')).toEqual({ sort: 'name', dir: 'asc' });
    const ascending = { ...state, sort: 'name', dir: 'asc' as const };
    expect(nextInventorySort(ascending, 'name')).toEqual({ sort: 'name', dir: 'desc' });
    const descending = { ...state, sort: 'name', dir: 'desc' as const };
    expect(nextInventorySort(descending, 'name')).toEqual({ sort: 'name', dir: 'asc' });
  });
});
