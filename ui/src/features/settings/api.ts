/**
 * Settings endpoints (`lib/panel/settings.php`).
 *
 * The wire shapes are the ones the legacy page already works with:
 *
 *  - settings arrive *grouped*, so the tab layout is decided by the server
 *    (`general`/`assets` share a tab, `storage` and `api` have their own);
 *  - sources, fields, API keys and users come back as the list the write just
 *    produced, so a mutation never needs a second round trip;
 *  - secrets are write-only — `value` is always empty and `configured` says
 *    whether something is stored (in the DB or in the environment).
 */

import { apiRequest, apiRequestEnvelope } from '@/api/client';

export type SettingOption = { value: string; label: string };

/** `type` mirrors `settings_definitions()` in `lib/settings.php`. */
export type SettingType =
  | 'bool'
  | 'int'
  | 'timezone'
  | 'locale'
  | 'select'
  | 'source'
  | 'secret'
  | 'textarea'
  | 'text';

/**
 * One setting. `label_key` is the `setting_*` catalogue key the server resolved;
 * `label` is the English fallback for a catalogue that has no entry yet.
 */
export type SettingEntry = {
  key: string;
  group: string;
  label: string;
  label_key: string;
  type: SettingType;
  options: SettingOption[];
  min: number | null;
  max: number | null;
  /** Where the effective value comes from: `db`, `env` or `default`. */
  source: 'db' | 'env' | 'default';
  secret: boolean;
  /** Secrets only: something is stored, so "leave blank to keep" applies. */
  configured: boolean | null;
  value: string;
};

export type SettingGroup = { id: string; label_key: string; settings: SettingEntry[] };

export type SettingsSource = {
  slug: string;
  label: string;
  color: string | null;
  is_system: boolean;
  is_default: boolean;
  /** Assets currently pointing at this source (a non-zero value blocks delete). */
  usage: number;
};

export type SettingsLocale = { code: string; native: string; label: string; dir: 'ltr' | 'rtl' };

export type SettingsPayload = {
  groups: SettingGroup[];
  sources: SettingsSource[];
  locales: SettingsLocale[];
  timezones: string[];
  themes: string[];
};

/** Body of `PATCH /settings`; `clear` wipes a stored secret. */
export type SettingsPatchBody = {
  values: Record<string, string>;
  clear?: string[];
};

export type SettingsSaveResult = {
  payload: SettingsPayload;
  /** Keys the server actually wrote (an untouched secret is not one of them). */
  saved: string[];
};

export function fetchSettings(): Promise<SettingsPayload> {
  return apiRequest<SettingsPayload>('/settings');
}

export async function saveSettings(body: SettingsPatchBody): Promise<SettingsSaveResult> {
  const result = await apiRequestEnvelope<SettingsPayload>('/settings', { method: 'PATCH', body });
  const saved = result.meta.saved;
  return { payload: result.data, saved: Array.isArray(saved) ? saved.map(String) : [] };
}

// --- Sources ---------------------------------------------------------------

export type SourceInput = {
  slug: string;
  label: string;
  color: string | null;
  is_default: boolean;
};

export type SourcesPayload = { sources: SettingsSource[] };

export function fetchSources(): Promise<SourcesPayload> {
  return apiRequest<SourcesPayload>('/settings/sources');
}

export function createSource(input: SourceInput): Promise<SourceInput & SourcesPayload> {
  return apiRequest<SourceInput & SourcesPayload>('/settings/sources', { method: 'POST', body: input });
}

export function updateSource(slug: string, input: Omit<SourceInput, 'slug'>): Promise<SourcesPayload> {
  return apiRequest<SourcesPayload>(`/settings/sources/${encodeURIComponent(slug)}`, {
    method: 'PATCH',
    body: input,
  });
}

export function deleteSource(slug: string): Promise<SourcesPayload> {
  return apiRequest<SourcesPayload>(`/settings/sources/${encodeURIComponent(slug)}`, { method: 'DELETE' });
}

// --- Custom fields ---------------------------------------------------------

export type FieldType = { value: string; label: string; input: string };

export type FieldRules = {
  min?: number | null;
  max?: number | null;
  maxlength?: number | null;
  pattern?: string | null;
};

export type FieldRow = {
  id: number;
  key: string;
  label: string;
  label_i18n: Record<string, string>;
  type: string;
  options: string[];
  default: string;
  validation: FieldRules;
  required: boolean;
  unique: boolean;
  searchable: boolean;
  in_grid: boolean;
  editable_roles: string[];
  sort_order: number;
  active: boolean;
  /** Stored values referencing this definition. */
  usage: number;
};

export type FieldsPayload = { fields: FieldRow[]; types: FieldType[]; roles: string[] };

/**
 * Body of `POST /settings/fields` and `PATCH /settings/fields/{id}`.
 *
 * Short keys on purpose: `panel_settings_field_apply()` only copies the names the
 * model understands (`key`, `type`, `default`, `required`, …), so sending the
 * database column names would be silently ignored.
 */
export type FieldInput = {
  key: string;
  label: string;
  type: string;
  options: string[];
  default: string;
  validation: FieldRules;
  required: boolean;
  unique: boolean;
  searchable: boolean;
  in_grid: boolean;
  editable_roles: string[];
  sort_order: number;
  active: boolean;
};

export function fetchFields(): Promise<FieldsPayload> {
  return apiRequest<FieldsPayload>('/settings/fields');
}

export function createField(input: FieldInput): Promise<FieldsPayload> {
  return apiRequest<FieldsPayload>('/settings/fields', { method: 'POST', body: input });
}

export function updateField(id: number, input: FieldInput): Promise<FieldsPayload> {
  return apiRequest<FieldsPayload>(`/settings/fields/${id}`, { method: 'PATCH', body: input });
}

export function deleteField(id: number): Promise<FieldsPayload> {
  return apiRequest<FieldsPayload>(`/settings/fields/${id}`, { method: 'DELETE' });
}

/** New order, first id first (`POST /settings/fields/reorder`). */
export function reorderFields(ids: number[]): Promise<FieldsPayload> {
  return apiRequest<FieldsPayload>('/settings/fields/reorder', { method: 'POST', body: { ids } });
}

// --- API keys --------------------------------------------------------------

export type ApiKeyRow = {
  id: number;
  label: string;
  prefix: string;
  company: string | null;
  source_slug: string | null;
  allowed_sources: string[];
  ip_allowlist: string;
  can_read: boolean;
  can_write: boolean;
  can_delete: boolean;
  is_active: boolean;
  created_at: string | null;
  created_by: string | null;
  rotated_at: string | null;
  last_used_at: string | null;
  last_used_ip: string | null;
};

export type ApiKeysPayload = { keys: ApiKeyRow[]; sources: SettingsSource[] };

/** Body of `POST /settings/api-keys` / `PATCH /settings/api-keys/{id}`. */
export type ApiKeyInput = {
  label: string;
  company: string;
  source_slug: string;
  allowed_sources: string;
  ip_allowlist: string;
  can_read: boolean;
  can_write: boolean;
  can_delete: boolean;
  is_active: boolean;
};

/** A write that minted a token (`POST`, `rotate`); the token is shown once. */
export type ApiKeyCreated = { payload: ApiKeysPayload; id: number; token: string };

export function fetchApiKeys(): Promise<ApiKeysPayload> {
  return apiRequest<ApiKeysPayload>('/settings/api-keys');
}

function readToken(meta: Record<string, unknown>): string {
  return typeof meta.token === 'string' ? meta.token : '';
}

export async function createApiKey(input: ApiKeyInput): Promise<ApiKeyCreated> {
  const result = await apiRequestEnvelope<ApiKeysPayload>('/settings/api-keys', { method: 'POST', body: input });
  return { payload: result.data, id: Number(result.meta.id ?? 0), token: readToken(result.meta) };
}

export function updateApiKey(id: number, input: ApiKeyInput): Promise<ApiKeysPayload> {
  return apiRequest<ApiKeysPayload>(`/settings/api-keys/${id}`, { method: 'PATCH', body: input });
}

/** New token for the same scopes; the previous one stops working immediately. */
export async function rotateApiKey(id: number): Promise<ApiKeyCreated> {
  const result = await apiRequestEnvelope<ApiKeysPayload>(`/settings/api-keys/${id}/rotate`, {
    method: 'POST',
    body: {},
  });
  return { payload: result.data, id: Number(result.meta.id ?? id), token: readToken(result.meta) };
}

export function deleteApiKey(id: number): Promise<ApiKeysPayload> {
  return apiRequest<ApiKeysPayload>(`/settings/api-keys/${id}`, { method: 'DELETE' });
}

// --- Users (superadmin) ----------------------------------------------------

export type UserRow = { email: string; company: string; role: string; is_self: boolean };
export type UsersPayload = { users: UserRow[]; roles: string[] };

export function fetchUsers(): Promise<UsersPayload> {
  return apiRequest<UsersPayload>('/settings/users');
}

/** The e-mail is the identity of a `users` row, so it is the path parameter. */
export function updateUserRole(email: string, role: string): Promise<UsersPayload> {
  return apiRequest<UsersPayload>(`/settings/users/${encodeURIComponent(email)}`, {
    method: 'PATCH',
    body: { role },
  });
}

// --- Maintenance -----------------------------------------------------------

export type MigrationStatus = {
  ready: boolean;
  ledger: boolean;
  applied: string[];
  pending: string[];
};

export type MaintenanceDiagnostics = {
  app_version: string;
  app_env: string;
  php_version: string;
  database: string;
  db_version: string;
  timezone: string;
  now: string;
  encryption: boolean;
  counts: Record<string, number>;
};

export type MaintenancePayload = { migrations: MigrationStatus; diagnostics: MaintenanceDiagnostics };

export type MigrationRun = {
  dry_run: boolean;
  applied: string[];
  skipped: string[];
  log: string[];
  ready: boolean;
};

export function fetchMaintenance(): Promise<MaintenancePayload> {
  return apiRequest<MaintenancePayload>('/settings/maintenance');
}

/** `dryRun` defaults to the server's own default: list, do not apply. */
export async function runMigrations(
  dryRun: boolean,
): Promise<{ payload: MaintenancePayload; run: MigrationRun }> {
  const result = await apiRequestEnvelope<MaintenancePayload>('/settings/maintenance/migrate', {
    method: 'POST',
    body: { dry_run: dryRun },
  });
  const meta = result.meta;
  return {
    payload: result.data,
    run: {
      dry_run: meta.dry_run === true,
      applied: Array.isArray(meta.applied) ? meta.applied.map(String) : [],
      skipped: Array.isArray(meta.skipped) ? meta.skipped.map(String) : [],
      log: Array.isArray(meta.log) ? meta.log.map(String) : [],
      ready: meta.ready === true,
    },
  };
}
