import { describe, expect, it } from 'vitest';

import {
  applyFilters,
  applySort,
  defaultGridState,
  devicesFilterQuery,
  devicesPageQuery,
  effectiveSort,
  gridSearchString,
  hasActiveFilters,
  nextSort,
  parseGridSearch,
  sortedDir,
  type GridState,
} from './gridSearch';

const defaults = { sort: 'updated_at', dir: 'desc', perPage: 25 } as const;

/** Apply the header-click cycle n times on one column. */
function click(state: GridState, column: string, times: number): GridState {
  let next = state;
  for (let i = 0; i < times; i += 1) {
    next = applySort(next, nextSort(next, column, defaults));
  }
  return next;
}

describe('parseGridSearch', () => {
  it('returns the defaults for an empty query', () => {
    const state = parseGridSearch('', defaults);
    expect(state).toEqual(defaultGridState(defaults));
    expect(effectiveSort(state, defaults)).toEqual({ sort: 'updated_at', dir: 'desc' });
  });

  it('round-trips every supported parameter', () => {
    const parsed = parseGridSearch(
      '?q=dell&source=intune&status=In%20Use&company=acme&byod=true&trashed=1&sort=SN&dir=asc&page=3&per_page=50',
      defaults,
    );

    expect(parsed).toEqual({
      q: 'dell',
      source: 'intune',
      status: 'In Use',
      company: 'acme',
      byod: 'true',
      trashed: true,
      sort: 'SN',
      dir: 'asc',
      page: 3,
      perPage: 50,
    });
  });

  it('ignores garbage instead of throwing', () => {
    const parsed = parseGridSearch('?byod=maybe&dir=sideways&page=0&per_page=-4&trashed=nope', defaults);
    expect(parsed.byod).toBe('');
    expect(parsed.dir).toBe('');
    expect(parsed.page).toBe(1);
    expect(parsed.perPage).toBe(25);
    expect(parsed.trashed).toBe(false);
  });
});

describe('gridSearchString', () => {
  it('omits defaults so a plain view has a clean URL', () => {
    expect(gridSearchString(defaultGridState(defaults), defaults)).toBe('');
  });

  it('serialises sort, filters and paging', () => {
    const state: GridState = {
      ...defaultGridState(defaults),
      q: 'dell',
      status: 'In Stock',
      sort: 'SN',
      dir: 'asc',
      page: 2,
      perPage: 50,
    };
    expect(gridSearchString(state, defaults)).toBe('?q=dell&status=In+Stock&sort=SN&dir=asc&page=2&per_page=50');
  });

  it('never writes a sort without a direction', () => {
    const state: GridState = { ...defaultGridState(defaults), sort: 'SN' };
    expect(gridSearchString(state, defaults)).toBe('?sort=SN&dir=desc');
  });
});

describe('nextSort — the sorting cycle that regressed in 2279453', () => {
  it('sorts an unsorted column ascending, then descending, then back to the default', () => {
    const base = defaultGridState(defaults);

    const first = nextSort(base, 'SN', defaults);
    expect(first).toEqual({ sort: 'SN', dir: 'asc' });

    const second = nextSort(first, 'SN', defaults);
    expect(second).toEqual({ sort: 'SN', dir: 'desc' });

    const third = nextSort(second, 'SN', defaults);
    expect(third).toEqual({ sort: '', dir: '' });
  });

  it('switches column while keeping the ascending-first rule', () => {
    const onSn = click(defaultGridState(defaults), 'SN', 2);
    expect(onSn.sort).toBe('SN');
    expect(onSn.dir).toBe('desc');

    const onHostname = click(onSn, 'Hostname', 1);
    expect(onHostname).toEqual({ ...onSn, sort: 'Hostname', dir: 'asc', page: 1 });
  });

  it('reports the effective direction for the header indicator', () => {
    expect(sortedDir(defaultGridState(defaults), 'SN')).toBeNull();
    expect(sortedDir({ sort: 'SN', dir: '' }, 'SN')).toBe('asc');
    expect(sortedDir({ sort: 'SN', dir: 'desc' }, 'SN')).toBe('desc');
    expect(sortedDir({ sort: 'SN', dir: 'desc' }, 'OS')).toBeNull();
  });

  it('does not leak the sort column into the URL after the full cycle', () => {
    const state = click(defaultGridState(defaults), 'source', 3);
    expect(state.sort).toBe('');
    expect(gridSearchString(state, defaults)).toBe('');
    expect(effectiveSort(state, defaults)).toEqual({ sort: 'updated_at', dir: 'desc' });
  });
});

describe('page resets', () => {
  const onPageThree: GridState = { ...defaultGridState(defaults), page: 3 };

  it('returns to page 1 when the sort changes', () => {
    expect(applySort(onPageThree, { sort: 'files', dir: 'desc' }).page).toBe(1);
  });

  it('returns to page 1 when a filter changes', () => {
    expect(applyFilters(onPageThree, { q: 'dell' }).page).toBe(1);
  });
});

describe('request queries', () => {
  const state: GridState = {
    ...defaultGridState(defaults),
    q: 'dell',
    byod: 'false',
    trashed: true,
    sort: 'files',
    dir: 'desc',
    page: 2,
  };

  it('always sends the effective sort', () => {
    const query = devicesPageQuery(defaultGridState(defaults), defaults);
    expect(query).toBe('?sort=updated_at&dir=desc&page=1&per_page=25');
  });

  it('carries filters, sort and paging for the list request', () => {
    expect(devicesPageQuery(state, defaults)).toBe(
      '?sort=files&dir=desc&q=dell&byod=false&trashed=true&page=2&per_page=25',
    );
  });

  it('leaves paging out of the export request', () => {
    expect(devicesFilterQuery(state, defaults)).toBe('?sort=files&dir=desc&q=dell&byod=false&trashed=true');
  });
});

describe('hasActiveFilters', () => {
  it('is false only for the pristine grid', () => {
    expect(hasActiveFilters(defaultGridState(defaults))).toBe(false);
    expect(hasActiveFilters({ ...defaultGridState(defaults), byod: 'false' })).toBe(true);
    expect(hasActiveFilters({ ...defaultGridState(defaults), trashed: true })).toBe(true);
  });
});
