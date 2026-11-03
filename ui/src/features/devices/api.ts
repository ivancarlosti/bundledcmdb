/**
 * Device endpoints and their wire types (`lib/panel/devices.php`).
 *
 * Rows are keyed the same way `asset_column_defs()` is, so a column definition
 * coming from `/meta` can read a cell without a translation table: the API
 * already emits snake_case keys for every asset column.
 */

import { apiRequest, apiRequestEnvelope, apiUrl } from '@/api/client';
import { devicesFilterQuery, devicesPageQuery, type GridState } from '@/features/grid/gridSearch';

export type DeviceRow = {
  id: number;
  uuid: string | null;
  sn: string | null;
  os: string | null;
  os_version: string | null;
  hostname: string | null;
  mobile: boolean | null;
  manufacturer: string | null;
  last_seen: string | null;
  user_email: string | null;
  byod: boolean | null;
  status: string | null;
  warranty: string | null;
  asset: string | null;
  purchase_date: string | null;
  cypher_id: string | null;
  cypher_key: string | null;
  notes: string | null;
  cpus: string | null;
  hds: string | null;
  hds_types: string | null;
  hds_space_gb: string | null;
  network_adapters: string | null;
  mac_addresses: string | null;
  eset_components: string | null;
  primary_local_ip: string | null;
  primary_remote_ip: string | null;
  company: string | null;
  source: string | null;
  source_ref: string | null;
  created_at: string | null;
  updated_at: string | null;
  created_by: string | null;
  updated_by: string | null;
  deleted_at: string | null;
  files_count: number;
  custom: Record<string, unknown>;
};

export type DeviceFile = {
  id: number;
  name: string;
  path: string;
  mime: string | null;
  size: number | null;
  uploaded_at: string | null;
};

export type DeviceDetail = DeviceRow & { files: DeviceFile[] };

export type DeviceListPayload = {
  rows: DeviceRow[];
  total: number;
  page: number;
  per_page: number;
  pages: number;
};

/** Column definition as published by `GET /meta` (labels are not translated there). */
export type MetaColumn = {
  key: string;
  /** Property of `DeviceRow` that carries this column (snake_case on the wire). */
  api_key: string;
  label_key: string;
  label: string;
  type: string;
  in_grid: boolean;
  sortable: boolean;
  editable: boolean;
  virtual: boolean;
};

export type MetaField = {
  id: number;
  key: string;
  label: string;
  label_i18n: Record<string, string> | null;
  type: string;
  options: string[];
  required: boolean;
  unique: boolean;
  searchable: boolean;
  in_grid: boolean;
  editable_roles: string[];
  sort_order: number;
};

export type MetaSource = {
  slug: string;
  label: string;
  color: string | null;
  is_system: boolean;
  is_default: boolean;
};

export type MetaPayload = {
  columns: MetaColumn[];
  statuses: { value: string; label_key: string }[];
  sources: MetaSource[];
  fields: MetaField[];
  companies: string[];
  locales: { code: string; native: string; label: string; dir: 'ltr' | 'rtl' }[];
  themes: string[];
  pageSizes: number[];
  capabilities: { role: string; rank: number; can_write: boolean; can_admin: boolean; can_superadmin: boolean };
  defaultSort: { sort: string; dir: 'asc' | 'desc' };
  virtualSorts: string[];
  /** Wire keys the current role may write (server-side role matrix). */
  writeColumns: { create: string[]; update: string[] };
  limits: { requireSn: boolean; maxUploadBytes: number };
  schemaReady: boolean;
};

/** Meta block returned alongside a device list (sort + effective filters). */
export type DeviceListMeta = {
  sort?: string;
  dir?: string;
  filters?: Record<string, unknown>;
  generated_at?: string;
};

export type DevicesResult = {
  payload: DeviceListPayload;
  meta: DeviceListMeta;
};

export function fetchMeta(): Promise<MetaPayload> {
  return apiRequest<MetaPayload>('/meta');
}

export async function fetchDevices(state: GridState): Promise<DevicesResult> {
  const result = await apiRequestEnvelope<DeviceListPayload>(`/devices${devicesPageQuery(state)}`);
  return { payload: result.data, meta: result.meta as DeviceListMeta };
}

export function fetchDevice(id: number): Promise<DeviceDetail> {
  return apiRequest<DeviceDetail>(`/devices/${id}`);
}

/** Direct link for the CSV export — same filters and sort, no paging. */
export function deviceExportUrl(state: GridState): string {
  return apiUrl(`/devices/export${devicesFilterQuery(state)}`);
}

/**
 * Body of a write call: snake_case keys (the same ones `/meta` publishes as
 * `api_key`), an optional `custom` map and — for `PATCH` — the timestamp the
 * form was rendered from, so a concurrent edit becomes a `409 stale_record`
 * instead of a lost update.
 */
export type DeviceWriteBody = {
  custom?: Record<string, unknown>;
  expected_updated_at?: string;
  [key: string]: unknown;
};

export type DeviceDeleteResult = { id: number; deleted: boolean; hard: boolean };

export function createDevice(body: DeviceWriteBody): Promise<DeviceDetail> {
  return apiRequest<DeviceDetail>('/devices', { method: 'POST', body });
}

export function updateDevice(id: number, body: DeviceWriteBody): Promise<DeviceDetail> {
  return apiRequest<DeviceDetail>(`/devices/${id}`, { method: 'PATCH', body });
}

export function deleteDevice(id: number, hard = false): Promise<DeviceDeleteResult> {
  return apiRequest<DeviceDeleteResult>(`/devices/${id}${hard ? '?hard=1' : ''}`, {
    method: 'DELETE',
  });
}

/** Restore a soft-deleted device; the payload is the refreshed device. */
export async function restoreDevice(id: number): Promise<{ device: DeviceDetail; restored: boolean }> {
  const result = await apiRequestEnvelope<DeviceDetail>(`/devices/${id}/restore`, { method: 'POST' });
  return { device: result.data, restored: result.meta.restored === true };
}

/** Upload one attachment (multipart; the server puts the object in S3). */
export function uploadDeviceFile(id: number, file: File): Promise<DeviceFile> {
  const form = new FormData();
  form.set('file', file);
  return apiRequest<DeviceFile>(`/devices/${id}/files`, { method: 'POST', form });
}

export function deleteDeviceFile(id: number, fileId: number): Promise<{ id: number; deleted: boolean }> {
  return apiRequest<{ id: number; deleted: boolean }>(`/devices/${id}/files/${fileId}`, { method: 'DELETE' });
}

/** Operations `POST /devices/bulk` understands (`lib/panel/device_writes.php`). */
export type BulkOp = 'status' | 'byod' | 'delete' | 'restore';

/** One row the server refused to change, with the reason the UI shows back. */
export type BulkSkipped = {
  id: number;
  code: string;
  message: string;
  errors?: Record<string, string>;
};

export type BulkResult = {
  op: BulkOp;
  /** Ids written by this request. */
  updated: number[];
  /** Ids left alone: stale, deleted, out of scope or invalid. */
  skipped: BulkSkipped[];
};

/**
 * Apply one operation to many devices.
 *
 * The server writes row by row (each in its own transaction) and answers with
 * `updated` + `skipped`, so a partially failing selection still reports the rows
 * it did change instead of one opaque 4xx.
 */
export function bulkDevices(
  op: BulkOp,
  ids: number[],
  options: { value?: string | boolean; hard?: boolean } = {},
): Promise<BulkResult> {
  const body: Record<string, unknown> = { ids, op };
  if (options.value !== undefined) {
    body.value = options.value;
  }
  if (options.hard !== undefined) {
    body.hard = options.hard;
  }
  return apiRequest<BulkResult>('/devices/bulk', { method: 'POST', body });
}
