/**
 * Grid state <-> URL, and the sorting state machine.
 *
 * The URL is the single source of truth for filters, sort and paging, which is
 * what makes a view shareable and survivable across a reload
 * (`/app/devices?sort=SN&dir=asc&page=2`). Keeping the transitions here — pure
 * functions, no React — means the sort behaviour that regressed in 2279453 is
 * covered by unit tests instead of by clicking around.
 *
 * Sorting rules:
 *  - clicking an unsorted column sorts ascending;
 *  - clicking it again sorts descending;
 *  - clicking a third time returns to the server default (`updated_at DESC`),
 *    which is reported as an empty `sort` so the URL stays clean;
 *  - every change of sort or filter resets the page (no empty page 4 of 1).
 */

export type SortDir = 'asc' | 'desc';
export type ByodFilter = '' | 'true' | 'false';

export type GridState = {
  q: string;
  source: string;
  status: string;
  company: string;
  byod: ByodFilter;
  trashed: boolean;
  /** Empty string = "use the server default". */
  sort: string;
  /** Empty string = "use the default direction". */
  dir: SortDir | '';
  page: number;
  perPage: number;
};

export type GridDefaults = {
  sort: string;
  dir: SortDir;
  perPage: number;
};

export const GRID_DEFAULTS: GridDefaults = { sort: 'updated_at', dir: 'desc', perPage: 25 };

export const BYOD_OPTIONS: readonly ByodFilter[] = ['', 'true', 'false'];

function isSortDir(value: unknown): value is SortDir {
  return value === 'asc' || value === 'desc';
}

function toPositiveInt(value: string | null): number | null {
  if (value === null || !/^\d+$/.test(value)) {
    return null;
  }
  const parsed = Number.parseInt(value, 10);
  return parsed > 0 ? parsed : null;
}

export function defaultGridState(defaults: GridDefaults = GRID_DEFAULTS): GridState {
  return {
    q: '',
    source: '',
    status: '',
    company: '',
    byod: '',
    trashed: false,
    sort: '',
    dir: '',
    page: 1,
    perPage: defaults.perPage,
  };
}


/** Parse a query string (with or without a leading `?`). Never throws. */
export function parseGridSearch(search: string, defaults: GridDefaults = GRID_DEFAULTS): GridState {
  const params = new URLSearchParams(search.startsWith('?') ? search.slice(1) : search);
  const state = defaultGridState(defaults);

  state.q = (params.get('q') ?? '').trim();
  state.source = (params.get('source') ?? '').trim();
  state.status = (params.get('status') ?? '').trim();
  state.company = (params.get('company') ?? '').trim();

  const byod = params.get('byod') ?? '';
  if (byod === 'true' || byod === 'false') {
    state.byod = byod;
  }

  const trashed = (params.get('trashed') ?? '').toLowerCase();
  state.trashed = trashed === '1' || trashed === 'true';

  state.sort = (params.get('sort') ?? '').trim();

  const dir = (params.get('dir') ?? '').toLowerCase();
  state.dir = isSortDir(dir) ? dir : '';

  state.page = toPositiveInt(params.get('page')) ?? 1;
  state.perPage = toPositiveInt(params.get('per_page')) ?? defaults.perPage;

  return state;
}

/** Serialise the state; defaults are omitted so URLs stay short and readable. */
export function gridSearchString(state: GridState, defaults: GridDefaults = GRID_DEFAULTS): string {
  const params = new URLSearchParams();

  if (state.q !== '') {
    params.set('q', state.q);
  }
  if (state.source !== '') {
    params.set('source', state.source);
  }
  if (state.status !== '') {
    params.set('status', state.status);
  }
  if (state.company !== '') {
    params.set('company', state.company);
  }
  if (state.byod !== '') {
    params.set('byod', state.byod);
  }
  if (state.trashed) {
    params.set('trashed', '1');
  }
  if (state.sort !== '') {
    params.set('sort', state.sort);
    params.set('dir', state.dir === '' ? defaults.dir : state.dir);
  }
  if (state.page > 1) {
    params.set('page', String(state.page));
  }
  if (state.perPage !== defaults.perPage) {
    params.set('per_page', String(state.perPage));
  }

  const query = params.toString();
  return query === '' ? '' : `?${query}`;
}

/** Sort actually sent to the API (defaults filled in). */
export function effectiveSort(
  state: Pick<GridState, 'sort' | 'dir'>,
  defaults: GridDefaults = GRID_DEFAULTS,
): { sort: string; dir: SortDir } {
  return {
    sort: state.sort === '' ? defaults.sort : state.sort,
    dir: state.dir === '' ? defaults.dir : state.dir,
  };
}

/** Direction the column is currently sorted by, or null when it is not. */
export function sortedDir(state: Pick<GridState, 'sort' | 'dir'>, column: string): SortDir | null {
  return state.sort === column ? (state.dir === '' ? 'asc' : state.dir) : null;
}

/**
 * Next state for a header click: unsorted → asc → desc → default.
 *
 * Returns only the sort/dir part so callers compose it with the page reset.
 */
export function nextSort(
  state: Pick<GridState, 'sort' | 'dir'>,
  column: string,
  _defaults: GridDefaults = GRID_DEFAULTS,
): { sort: string; dir: SortDir | '' } {
  const current = sortedDir(state, column);
  if (current === null) {
    return { sort: column, dir: 'asc' };
  }
  if (current === 'asc') {
    return { sort: column, dir: 'desc' };
  }
  // Third click: back to the server default, which keeps `sort` out of the URL.
  return { sort: '', dir: '' };
}

/** Apply a filter patch and return to page 1. */
export function applyFilters(
  state: GridState,
  patch: Partial<Pick<GridState, 'q' | 'source' | 'status' | 'company' | 'byod' | 'trashed'>>,
): GridState {
  return { ...state, ...patch, page: 1 };
}

/** Apply a sort patch and return to page 1. */
export function applySort(state: GridState, patch: { sort: string; dir: SortDir | '' }): GridState {
  return { ...state, ...patch, page: 1 };
}

/** True when any filter is active (drives the "clear" button). */
export function hasActiveFilters(state: GridState): boolean {
  return (
    state.q !== '' ||
    state.source !== '' ||
    state.status !== '' ||
    state.company !== '' ||
    state.byod !== '' ||
    state.trashed
  );
}

/** Query string for `GET /devices` (filters + sort, no paging). */
export function devicesFilterQuery(state: GridState, defaults: GridDefaults = GRID_DEFAULTS): string {
  const { sort, dir } = effectiveSort(state, defaults);
  const params = new URLSearchParams();
  params.set('sort', sort);
  params.set('dir', dir);

  if (state.q !== '') {
    params.set('q', state.q);
  }
  if (state.source !== '') {
    params.set('source', state.source);
  }
  if (state.status !== '') {
    params.set('status', state.status);
  }
  if (state.company !== '') {
    params.set('company', state.company);
  }
  if (state.byod !== '') {
    params.set('byod', state.byod);
  }
  if (state.trashed) {
    params.set('trashed', 'true');
  }

  return `?${params.toString()}`;
}

/** Same as above plus paging, for the list request. */
export function devicesPageQuery(state: GridState, defaults: GridDefaults = GRID_DEFAULTS): string {
  return `${devicesFilterQuery(state, defaults)}&page=${state.page}&per_page=${state.perPage}`;
}

