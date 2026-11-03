import type { DeviceDetail, DeviceWriteBody, MetaField, MetaPayload } from './api';

/** Value a form control holds for one column or custom field. */
export type FormValue = string | boolean;

export type FormValues = Record<string, FormValue>;

/**
 * Build the initial values for a form from `/meta` and (when editing) the row.
 *
 * Only the writable keys are read, so a column the role cannot edit can never be
 * submitted by accident.
 */
export function initialFormValues(
  keys: string[],
  device: DeviceDetail | null,
  fields: MetaField[],
  types: Record<string, string> = {},
): { values: FormValues; custom: FormValues } {
  const values: FormValues = {};
  const record = (device ?? {}) as unknown as Record<string, unknown>;

  for (const key of keys) {
    if (key === 'source') {
      values[key] = typeof record[key] === 'string' ? (record[key] as string) : '';
      continue;
    }
    const raw = record[key];
    if (types[key] === 'bool') {
      values[key] = raw === true || raw === 1 || raw === '1';
      continue;
    }
    if (typeof raw === 'boolean') {
      values[key] = raw;
      continue;
    }
    if (raw === null || raw === undefined) {
      values[key] = '';
      continue;
    }
    // `datetime-local` inputs want `YYYY-MM-DDTHH:mm`, not a full ISO string.
    values[key] = types[key] === 'datetime' ? String(raw).slice(0, 16) : String(raw);
  }

  const custom: FormValues = {};
  const customValues = (device?.custom ?? {}) as Record<string, unknown>;
  for (const field of fields) {
    const raw = customValues[field.key];
    if (field.type === 'bool') {
      custom[field.key] = raw === true || raw === 1 || raw === '1';
      continue;
    }
    custom[field.key] = raw === null || raw === undefined ? '' : String(raw);
  }

  return { values, custom };
}

/**
 * Request body for `POST /devices` / `PATCH /devices/{id}`.
 *
 * Empty strings are sent as `null` so clearing a field really clears it (the
 * server normalises both to `NULL`, but `null` keeps the intent explicit), and
 * booleans stay booleans because the schema stores `BYOD`/`Mobile` as flags.
 */
export function buildDevicePayload(values: FormValues, custom: FormValues, fields: MetaField[]): DeviceWriteBody {
  const body: DeviceWriteBody = {};

  for (const [key, value] of Object.entries(values)) {
    body[key] = typeof value === 'boolean' ? value : value.trim() === '' ? null : value.trim();
  }

  const customBody: Record<string, unknown> = {};
  for (const field of fields) {
    if (!(field.key in custom)) {
      continue;
    }
    const value = custom[field.key];
    if (typeof value === 'boolean') {
      customBody[field.key] = value;
      continue;
    }
    customBody[field.key] = value.trim() === '' ? null : value.trim();
  }
  if (Object.keys(customBody).length > 0) {
    body.custom = customBody;
  }

  return body;
}

/** Keys a form renders: `writeColumns.create` when creating, `.update` otherwise. */
export function formKeys(meta: MetaPayload, mode: 'create' | 'update'): string[] {
  const declared = mode === 'create' ? meta.writeColumns.create : meta.writeColumns.update;
  // Only keys that map to a published column (or `source`) can be labelled.
  const known = new Set(meta.columns.map((column) => column.api_key));
  known.add('source');
  return declared.filter((key) => known.has(key));
}

/** Custom fields the current role may edit. */
export function formFields(meta: MetaPayload, role: string): MetaField[] {
  return meta.fields.filter((field) => field.editable_roles.includes(role));
}

/** Translate the `422` `errors` map into form keys (already snake_case). */
export function normalizeFieldErrors(errors: Record<string, string>): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [key, code] of Object.entries(errors)) {
    out[key] = code;
  }
  return out;
}
