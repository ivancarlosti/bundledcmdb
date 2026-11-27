/**
 * Assets and Systems endpoints (`lib/panel/inventory.php`).
 *
 * The two tabs are one resource with a different `kind`, so the wire types and the
 * fetchers are shared: only the path prefix and the display keys differ. Keys are
 * the catalogue column names themselves, so a column definition can read a cell
 * without a mapping table (unlike the legacy device columns).
 */

import { apiRequest, buildQuery } from '@/api/client';

export type InventoryKind = 'asset' | 'system';

/** One row as `inventory_payload()` emits it. */
export type InventoryRow = {
  id: number;
  kind: InventoryKind;
  name: string | null;
  brand: string | null;
  category: string | null;
  /** Assets only. */
  model: string | null;
  /** Systems only. */
  version: string | null;
  /** Assets only. */
  serial: string | null;
  /** Assets only. */
  location: string | null;
  status: string | null;
  purchase_date: string | null;
  /** Assets only. */
  warranty_end: string | null;
  /** Systems only. */
  license_expiry: string | null;
  common_usage: boolean;
  notes: string | null;
  company: string | null;
  /** Assignment list; always empty for a common-usage item. */
  users: string[];
  created_at: string | null;
  updated_at: string | null;
  created_by: string | null;
  updated_by: string | null;
};

/** Everything a write may send. `users` replaces the assignment set. */
export type InventoryWriteBody = Partial<
  Pick<
    InventoryRow,
    | 'name'
    | 'brand'
    | 'category'
    | 'model'
    | 'version'
    | 'serial'
    | 'location'
    | 'status'
    | 'purchase_date'
    | 'warranty_end'
    | 'license_expiry'
    | 'common_usage'
    | 'notes'
    | 'company'
  >
> & {
  users?: string[];
  /** Optimistic lock: the `updated_at` the form rendered. */
  expected_updated_at?: string;
};

export type InventoryListPayload = {
  rows: InventoryRow[];
  total: number;
  page: number;
  per_page: number;
  pages: number;
};

/** Query string of the list endpoint (already normalised by `inventoryState`). */
export type InventoryQuery = {
  q: string;
  status: string;
  category: string;
  common_usage: string;
  company: string;
  sort: string;
  dir: string;
  page: number;
  per_page: number;
};

export function inventoryPath(kind: InventoryKind): string {
  return kind === 'asset' ? '/assets' : '/systems';
}

export async function fetchInventory(kind: InventoryKind, query: InventoryQuery): Promise<InventoryListPayload> {
  const search = buildQuery({
    q: query.q,
    status: query.status,
    category: query.category,
    common_usage: query.common_usage,
    company: query.company,
    sort: query.sort,
    dir: query.dir,
    page: query.page,
    per_page: query.per_page,
  });
  return apiRequest<InventoryListPayload>(`${inventoryPath(kind)}${search}`);
}

export async function fetchInventoryItem(kind: InventoryKind, id: number): Promise<InventoryRow> {
  return apiRequest<InventoryRow>(`${inventoryPath(kind)}/${id}`);
}

export async function createInventoryItem(kind: InventoryKind, body: InventoryWriteBody): Promise<InventoryRow> {
  return apiRequest<InventoryRow>(inventoryPath(kind), { method: 'POST', body });
}

export async function updateInventoryItem(
  kind: InventoryKind,
  id: number,
  body: InventoryWriteBody,
): Promise<InventoryRow> {
  return apiRequest<InventoryRow>(`${inventoryPath(kind)}/${id}`, { method: 'PATCH', body });
}

export async function deleteInventoryItem(kind: InventoryKind, id: number): Promise<void> {
  await apiRequest<{ id: number; deleted: boolean }>(`${inventoryPath(kind)}/${id}`, { method: 'DELETE' });
}

/** Plural tab label key. */
export function inventoryListKey(kind: InventoryKind): string {
  return kind === 'asset' ? 'ui_assets' : 'ui_systems';
}

/** "New …" button label key. */
export function inventoryNewKey(kind: InventoryKind): string {
  return kind === 'asset' ? 'ui_new_asset' : 'ui_new_system';
}

/** "Edit …" heading key. */
export function inventoryEditKey(kind: InventoryKind): string {
  return kind === 'asset' ? 'ui_edit_asset' : 'ui_edit_system';
}

/** Empty-grid message key. */
export function inventoryEmptyKey(kind: InventoryKind): string {
  return kind === 'asset' ? 'ui_assets_empty' : 'ui_systems_empty';
}

/** Route base (`/assets` or `/systems`). */
export function inventoryRoute(kind: InventoryKind): '/assets' | '/systems' {
  return kind === 'asset' ? '/assets' : '/systems';
}

/**
 * Server validation code → translation key.
 *
 * Unknown codes fall back to the generic invalid-value message rather than putting
 * a raw `unknown_user` on screen.
 */
const ERROR_KEYS: Record<string, string> = {
  required: 'ui_err_required',
  too_long: 'ui_err_required',
  invalid_date: 'ui_err_invalid_date',
  invalid_option: 'ui_err_invalid_value',
  invalid_boolean: 'ui_err_invalid_value',
  invalid_value: 'ui_err_invalid_value',
  invalid_users: 'ui_err_invalid_value',
  invalid_email: 'ui_err_invalid_value',
  unknown_user: 'ui_err_unknown_user',
  common_usage_conflict: 'ui_err_common_usage',
  unknown_field: 'ui_err_unknown_field',
  not_applicable: 'ui_err_not_applicable',
  immutable: 'ui_err_forbidden',
  forbidden: 'ui_err_forbidden',
  company_required: 'ui_err_company_required',
};

export function inventoryErrorKey(code: string): string {
  return ERROR_KEYS[code] ?? 'ui_err_invalid_value';
}
