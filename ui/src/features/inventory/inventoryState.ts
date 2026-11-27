/**
 * Grid state of the Assets/Systems screens, bound to the address bar.
 *
 * Same idea as `features/grid/gridSearch.ts`, but the filter set is the inventory
 * one (`status`, `category`, `common_usage`) and there is no trash/byod/source.
 * `history.replaceState` keeps the browser back button useful while typing, and
 * `popstate` restores the state on navigation — the same trade-off the device grid
 * makes.
 */

import { useCallback, useEffect, useState } from 'react';

import type { InventoryQuery } from './api';

export type InventorySortDir = 'asc' | 'desc';
export type InventoryCommonUsage = '' | 'true' | 'false';

export type InventoryState = {
  q: string;
  status: string;
  category: string;
  commonUsage: InventoryCommonUsage;
  /** Empty = the server default (`updated_at desc`). */
  sort: string;
  dir: '' | InventorySortDir;
  page: number;
  perPage: number;
};

export function defaultInventoryState(perPage = 25): InventoryState {
  return { q: '', status: '', category: '', commonUsage: '', sort: '', dir: '', page: 1, perPage };
}

function positiveInt(value: string | null, fallback: number): number {
  const parsed = Number(value);
  return Number.isFinite(parsed) && parsed >= 1 ? Math.floor(parsed) : fallback;
}

/** Read the state out of `location.search`. */
export function parseInventorySearch(search: string, perPage = 25): InventoryState {
  const params = new URLSearchParams(search.startsWith('?') ? search.slice(1) : search);
  const common = params.get('common_usage');
  const dir = params.get('dir');

  return {
    q: params.get('q') ?? '',
    status: params.get('status') ?? '',
    category: params.get('category') ?? '',
    commonUsage: common === 'true' || common === 'false' ? common : '',
    sort: params.get('sort') ?? '',
    dir: dir === 'asc' || dir === 'desc' ? dir : '',
    page: positiveInt(params.get('page'), 1),
    perPage: positiveInt(params.get('per_page'), perPage),
  };
}

/**
 * Address-bar form of the state, with the defaults left out.
 *
 * `per_page` is only written when it differs from the session page size, so a
 * shared link keeps following the recipient's own preference.
 */
export function inventorySearchString(state: InventoryState, perPage = 25): string {
  const params = new URLSearchParams();
  if (state.q !== '') params.set('q', state.q);
  if (state.status !== '') params.set('status', state.status);
  if (state.category !== '') params.set('category', state.category);
  if (state.commonUsage !== '') params.set('common_usage', state.commonUsage);
  if (state.sort !== '') params.set('sort', state.sort);
  if (state.dir !== '') params.set('dir', state.dir);
  if (state.page > 1) params.set('page', String(state.page));
  if (state.perPage !== perPage) params.set('per_page', String(state.perPage));

  const query = params.toString();
  return query === '' ? '' : `?${query}`;
}

/** Request parameters of `GET /assets|/systems`. */
export function inventoryQuery(state: InventoryState, company = ''): InventoryQuery {
  return {
    q: state.q,
    status: state.status,
    category: state.category,
    common_usage: state.commonUsage,
    company,
    sort: state.sort,
    dir: state.dir,
    page: state.page,
    per_page: state.perPage,
  };
}

/** Stable react-query key (the query string, so paging/filtering caches apart). */
export function inventoryQueryKey(state: InventoryState, company = ''): string {
  return new URLSearchParams(
    Object.entries(inventoryQuery(state, company)).map(([key, value]) => [key, String(value)]),
  ).toString();
}

export function applyInventoryFilters(state: InventoryState, patch: Partial<InventoryState>): InventoryState {
  // Any filter change invalidates the page number: page 4 of a narrower result set
  // is usually empty, and the user reads that as "no rows".
  return { ...state, ...patch, page: patch.page ?? 1 };
}

export function hasInventoryFilters(state: InventoryState): boolean {
  return (
    state.q !== '' || state.status !== '' || state.category !== '' || state.commonUsage !== '' || state.sort !== ''
  );
}

export function nextInventorySort(state: InventoryState, column: string): { sort: string; dir: InventorySortDir } {
  if (state.sort !== column) {
    return { sort: column, dir: 'asc' };
  }
  return { sort: column, dir: state.dir === 'asc' ? 'desc' : 'asc' };
}

/** Grid state bound to the address bar (see the module comment). */
export function useInventoryState(perPage: number) {
  const [state, setState] = useState<InventoryState>(() =>
    parseInventorySearch(typeof window === 'undefined' ? '' : window.location.search, perPage),
  );

  useEffect(() => {
    const onPopState = () => setState(parseInventorySearch(window.location.search, perPage));
    window.addEventListener('popstate', onPopState);
    return () => window.removeEventListener('popstate', onPopState);
  }, [perPage]);

  const update = useCallback(
    (next: InventoryState | ((current: InventoryState) => InventoryState)) => {
      setState((current) => {
        const resolved = typeof next === 'function' ? next(current) : next;
        const search = inventorySearchString(resolved, perPage);
        window.history.replaceState(null, '', `${window.location.pathname}${search}`);
        return resolved;
      });
    },
    [perPage],
  );

  const reset = useCallback(() => {
    update(defaultInventoryState(perPage));
  }, [perPage, update]);

  return { state, update, reset };
}
