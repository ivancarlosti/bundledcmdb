/**
 * Device endpoints and their wire types (`lib/panel/devices.php`).
 *
 * Rows are keyed the same way `asset_column_defs()` is, so a column definition
 * coming from `/meta` can read a cell without a translation table: the API
 * already emits snake_case keys for every asset column.
 */

import { apiRequest, apiRequestEnvelope, apiUrl, buildQuery } from '@/api/client';
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

/** Direct link for the CSV/XLSX export — same filters and sort, no paging. */
export function deviceExportUrl(state: GridState, format: 'csv' | 'xlsx' = 'csv'): string {
  const query = devicesFilterQuery(state);
  const separator = query === '' ? '?' : '&';
  return apiUrl(`/devices/export${query}${separator}format=${format}`);
}

/** One account of the directory the "user e-mail" dropdown offers. */
export type CompanyUser = {
  email: string;
  company: string;
  role: string;
};

/**
 * Accounts of one company (`GET /users`).
 *
 * The server decides the scope: a company-bound caller always gets its own
 * company, whatever is asked for, so passing `''` from the form is safe.
 */
export function fetchCompanyUsers(company: string): Promise<CompanyUser[]> {
  return apiRequest<{ rows: CompanyUser[]; total: number }>(`/users${buildQuery({ company })}`).then(
    (data) => data.rows,
  );
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

/** What `POST /devices/{id}/files/presign` answers. */
export type UploadPlan =
  { mode: 'server' } | { mode: 's3'; url: string; key: string; headers: Record<string, string>; expires_in: number };

export function presignDeviceFile(
  id: number,
  input: { name: string; mime: string; size: number },
): Promise<UploadPlan> {
  return apiRequest<UploadPlan>(`/devices/${id}/files/presign`, { method: 'POST', body: input });
}

/**
 * Upload one attachment (`meta.limits.maxUploadBytes`: 100 MiB for both drivers).
 *
 * Uses `XMLHttpRequest`, not `fetch`, because this is the one request in the panel
 * whose *progress* matters: a 100 MB PDF over a slow link should show a percentage
 * instead of a spinner that looks stuck.
 *
 * With the S3 driver the browser PUTs straight to the bucket. That is the fast
 * path and the fragile one: the browser needs its own route to `s3.endpoint`
 * (`http://minio:9000` only resolves inside the Docker network) and a CORS rule on
 * the bucket. When that PUT fails, the object may still have landed — a blocked
 * response, not a blocked request — so the key is registered first and the bytes
 * go through PHP-FPM only when the registration is refused too.
 *
 * @param onProgress 0..1, called on the way up (never for the multipart fallback
 *   until the request completes, because XHR cannot report server-side progress)
 */
export async function uploadDeviceFile(
  id: number,
  file: File,
  onProgress?: (fraction: number) => void,
): Promise<DeviceFile> {
  const plan = await presignDeviceFile(id, { name: file.name, mime: file.type, size: file.size });
  if (plan.mode !== 's3') {
    return uploadThroughServer(id, file, onProgress);
  }

  try {
    await putWithProgress(plan.url, file, plan.headers, onProgress);
  } catch {
    return registerDirectUpload(id, plan.key, file).catch(() => uploadThroughServer(id, file, onProgress));
  }

  return registerDirectUpload(id, plan.key, file);
}

/** Register an object the browser already PUT to the bucket. */
function registerDirectUpload(id: number, key: string, file: File): Promise<DeviceFile> {
  return apiRequest<DeviceFile>(`/devices/${id}/files`, {
    method: 'POST',
    body: { key, name: file.name, mime: file.type, size: file.size },
  });
}

/** Multipart upload through the panel; the server writes S3 or local disk. */
async function uploadThroughServer(
  id: number,
  file: File,
  onProgress?: (fraction: number) => void,
): Promise<DeviceFile> {
  const form = new FormData();
  form.set('file', file);
  onProgress?.(0.1);
  const uploaded = await apiRequest<DeviceFile>(`/devices/${id}/files`, { method: 'POST', form });
  onProgress?.(1);
  return uploaded;
}

/** PUT one file to a presigned URL, reporting upload progress. */
function putWithProgress(
  url: string,
  file: File,
  headers: Record<string, string>,
  onProgress?: (fraction: number) => void,
): Promise<void> {
  return new Promise((resolve, reject) => {
    const request = new XMLHttpRequest();
    request.open('PUT', url, true);
    for (const [name, value] of Object.entries(headers)) {
      request.setRequestHeader(name, value);
    }
    request.upload.addEventListener('progress', (event) => {
      if (event.lengthComputable && event.total > 0) {
        onProgress?.(event.loaded / event.total);
      }
    });
    request.addEventListener('load', () => {
      if (request.status >= 200 && request.status < 300) {
        onProgress?.(1);
        resolve();
        return;
      }
      reject(new Error(`The object storage refused the upload (HTTP ${request.status}).`));
    });
    // status 0 means the request never got a response: DNS failure on
    // `s3.endpoint`, a CORS rule that does not allow the panel origin, or an
    // unreachable bucket. The message ends up in the toast, so it has to say so.
    request.addEventListener('error', () =>
      reject(new Error('The browser could not reach the object storage endpoint (CORS or DNS).')),
    );
    request.addEventListener('abort', () => reject(new Error('The upload was cancelled.')));
    request.send(file);
  });
}

/**
 * Download link for one attachment.
 *
 * A plain `<a href>`: the panel API answers `302` to a presigned S3 URL or streams
 * the file itself when `storage.driver = local`, so the browser handles the
 * download and the session cookie is sent as usual.
 */
export function deviceFileDownloadUrl(id: number, fileId: number): string {
  return apiUrl(`/devices/${id}/files/${fileId}/download`);
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

/**
 * One line of a device's audit trail (`GET /devices/{id}/events`).
 *
 * `changes` is keyed by the schema column (`SN`, `Status`, …), which is the same
 * spelling as `MetaColumn.key`, so the detail page can label it with the grid's
 * own `col_*` translation instead of a second dictionary.
 */
export type DeviceEvent = {
  id: number;
  asset_id: number | null;
  action: 'create' | 'update' | 'delete' | 'restore' | 'purge' | 'file_add' | 'file_remove' | string;
  actor: string;
  changes: Record<string, [string | null, string | null]>;
  note: string | null;
  created_at: string;
};

export function fetchDeviceEvents(id: number, limit = 50): Promise<DeviceEvent[]> {
  return apiRequest<{ rows: DeviceEvent[]; total: number }>(`/devices/${id}/events?limit=${limit}`).then(
    (data) => data.rows,
  );
}
