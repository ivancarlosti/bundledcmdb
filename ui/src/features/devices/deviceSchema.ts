import { z } from 'zod';

import type { MetaColumn, MetaField, MetaPayload } from './api';

/**
 * Form validation, shared by the create and edit screens.
 *
 * The server is authoritative (`asset_validate()` + `custom_values_save()` answer
 * `422` with a code per key), so this schema only has to cover what a round-trip
 * is wasted on: an empty required field, a number that is not a number, a date
 * that is not a date. Everything else — duplicates, role rules, sources — stays
 * server-side, because a client can never know it as well as the database.
 */

/**
 * Form keys grouped into tabs, in render order (`/meta.writeColumns` is written in
 * the wire spelling: `sn`, `hostname`, `cypher_id`). Unknown keys fall into
 * `more`, so a column added to the server still shows up.
 */
const TAB_COLUMNS: Record<string, readonly string[]> = {
  identity: ['sn', 'uuid', 'hostname', 'device_model', 'os', 'os_version', 'manufacturer', 'last_seen'],
  hardware: [
    'cpus',
    'gpus',
    'ram_gb',
    'hds',
    'hds_types',
    'hds_spaces_gb',
    'network_adapters',
    'mac_addresses',
    'eset_components',
    'primary_local_ip',
    'primary_remote_ip',
  ],
  assignment: [
    'status',
    'user_email',
    'logged_username',
    'asset',
    'byod',
    'mobile',
    'warranty',
    'purchase_date',
    'source',
  ],
  admin: ['cypher_id', 'cypher_key', 'notes'],
};

const TAB_ORDER = ['identity', 'hardware', 'assignment', 'admin', 'more'] as const;

export type FormTab = { id: string; keys: string[] };

/**
 * Split the writable keys into tabs.
 *
 * Every tab that ends up empty is dropped, so a manager (no `CypherID`) does not
 * get an "Admin" tab with nothing in it, and a column the server adds later
 * shows up under `more` instead of disappearing from the form.
 */
export function formTabs(keys: string[]): FormTab[] {
  const buckets = new Map<string, string[]>();
  for (const id of TAB_ORDER) {
    buckets.set(id, []);
  }

  for (const key of keys) {
    const owner = TAB_ORDER.find((id) => (TAB_COLUMNS[id] ?? []).includes(key)) ?? 'more';
    buckets.get(owner)?.push(key);
  }

  // Keep the declared order inside a tab, not the order the role list happened
  // to produce: `SN` before `Hostname` reads better than a random shuffle.
  const known = new Map<string, number>();
  let index = 0;
  for (const id of TAB_ORDER) {
    for (const key of TAB_COLUMNS[id] ?? []) {
      known.set(key, index++);
    }
  }

  return TAB_ORDER.map((id) => ({
    id,
    keys: (buckets.get(id) ?? []).sort((a, b) => (known.get(a) ?? 999) - (known.get(b) ?? 999)),
  })).filter((tab) => tab.keys.length > 0);
}

const NUMBER_TYPES = new Set(['int', 'integer', 'decimal', 'number']);
const DATE_TYPES = new Set(['date', 'datetime']);

function numberMessage(): string {
  return 'invalid_number';
}

/**
 * Build the zod schema for the current form.
 *
 * Values are validated where they live in the form: `values.<column>` for the
 * device columns and `custom.<field_key>` for the custom fields, so a `422` from
 * the server and a client-side error end up on the same input.
 */
export function buildDeviceSchema(
  keys: string[],
  columns: Map<string, MetaColumn>,
  fields: MetaField[],
  options: { requireSn: boolean } = { requireSn: false },
) {
  const values: Record<string, z.ZodTypeAny> = {};

  for (const key of keys) {
    const column = columns.get(key);
    const type = column?.type ?? 'text';
    let schema: z.ZodTypeAny = z.string();

    if (type === 'bool') {
      schema = z.boolean();
    } else if (NUMBER_TYPES.has(type)) {
      schema = z
        .string()
        .refine((value) => value.trim() === '' || /^-?\d+([.,]\d+)?$/.test(value.trim()), numberMessage());
    } else if (DATE_TYPES.has(type)) {
      schema = z.string().refine((value) => value.trim() === '' || !Number.isNaN(Date.parse(value)), 'invalid_date');
    }

    // `SN` is required when `assets.require_sn` is on (published as
    // `limits.requireSn`), which is exactly what the server checks first.
    if (key === 'sn' && options.requireSn) {
      schema = (schema as z.ZodString).refine((value) => value.trim() !== '', 'required');
    }

    values[key] = schema;
  }

  const custom: Record<string, z.ZodTypeAny> = {};
  for (const field of fields) {
    const base =
      field.type === 'bool'
        ? z.boolean()
        : field.type === 'number' || field.type === 'int'
          ? z.string().refine((value) => value.trim() === '' || /^-?\d+([.,]\d+)?$/.test(value.trim()), numberMessage())
          : z.string();

    custom[field.key] = field.required
      ? (base as z.ZodString).refine((value) => (typeof value === 'boolean' ? true : value.trim() !== ''), 'required')
      : base;
  }

  return z.object({
    values: z.object(values),
    custom: z.object(custom),
    company: z.string(),
  });
}

/** What the form holds: device columns, custom values and the company selector. */
export type DeviceFormModel = {
  values: Record<string, string | boolean>;
  custom: Record<string, string | boolean>;
  company: string;
};

/** `limits.requireSn` from `/meta`. */
export function requireSn(meta: MetaPayload): boolean {
  return meta.limits?.requireSn === true;
}
