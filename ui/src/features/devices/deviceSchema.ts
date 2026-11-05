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
 *
 * The tab layout lives in `deviceSections.ts`, which the detail page reads too:
 * one list decides where a field lives, for both screens.
 */

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
