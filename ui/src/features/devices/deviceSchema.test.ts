import { describe, expect, it } from 'vitest';

import type { MetaColumn, MetaField } from './api';
import { buildDeviceSchema, formTabs, requireSn } from './deviceSchema';

/**
 * The form's client-side rules.
 *
 * The server stays authoritative (`422` with a code per key); what matters here is
 * that the schema does not reject a valid payload and does catch the two mistakes
 * that are pure wasted round-trips: an empty required field and a number that is
 * not a number.
 */

const column = (apiKey: string, type: string): MetaColumn => ({
  key: apiKey,
  api_key: apiKey,
  label_key: `col_${apiKey}`,
  label: apiKey,
  type,
  in_grid: true,
  sortable: true,
  editable: true,
  virtual: false,
});

const field = (key: string, required = false): MetaField => ({
  id: 1,
  key,
  label: key,
  type: 'text',
  options: [],
  required,
  is_active: true,
  sort_order: 0,
  editable_roles: ['manager'],
});

function columnsOf(...keys: string[]): Map<string, MetaColumn> {
  const map = new Map<string, MetaColumn>();
  for (const key of keys) {
    map.set(key, column(key, key === 'byod' ? 'bool' : key === 'cpus' ? 'int' : 'text'));
  }
  return map;
}

describe('formTabs', () => {
  it('groups the wire keys into the fixed tab order', () => {
    const tabs = formTabs(['notes', 'sn', 'cpus', 'status', 'cypher_id']);

    expect(tabs.map((tab) => tab.id)).toEqual(['identity', 'hardware', 'assignment', 'admin']);
    expect(tabs[0]?.keys).toEqual(['sn']);
    expect(tabs[1]?.keys).toEqual(['cpus']);
    expect(tabs[2]?.keys).toEqual(['status']);
    expect(tabs[3]?.keys).toEqual(['cypher_id', 'notes']);
  });

  it('drops empty tabs and puts unknown keys last', () => {
    const tabs = formTabs(['sn', 'something_new']);

    expect(tabs.map((tab) => tab.id)).toEqual(['identity', 'more']);
    expect(tabs[1]?.keys).toEqual(['something_new']);
  });

  it('keeps a declared order inside a tab regardless of the role list', () => {
    const tabs = formTabs(['hostname', 'sn', 'manufacturer']);

    expect(tabs[0]?.keys).toEqual(['sn', 'hostname', 'manufacturer']);
  });
});

describe('buildDeviceSchema', () => {
  const model = (values: Record<string, unknown>, custom: Record<string, unknown> = {}) => ({
    values,
    custom,
    company: 'Acme',
  });

  it('accepts a valid payload', () => {
    const schema = buildDeviceSchema(['sn', 'hostname'], columnsOf('sn', 'hostname'), []);
    const result = schema.safeParse(model({ sn: 'SN-1', hostname: 'host-1' }));

    expect(result.success).toBe(true);
  });

  it('requires the serial number only when the server says so', () => {
    const withoutRequirement = buildDeviceSchema(['sn'], columnsOf('sn'), [], { requireSn: false });
    const withRequirement = buildDeviceSchema(['sn'], columnsOf('sn'), [], { requireSn: true });

    expect(withoutRequirement.safeParse(model({ sn: '' })).success).toBe(true);
    const result = withRequirement.safeParse(model({ sn: '   ' }));
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.path).toEqual(['values', 'sn']);
      expect(result.error.issues[0]?.message).toBe('required');
    }
  });

  it('rejects a non-numeric value in a numeric column', () => {
    const schema = buildDeviceSchema(['cpus'], columnsOf('cpus'), []);
    const result = schema.safeParse(model({ cpus: 'many' }));

    expect(result.success).toBe(false);
    expect(schema.safeParse(model({ cpus: '4' })).success).toBe(true);
    expect(schema.safeParse(model({ cpus: '' })).success).toBe(true);
  });

  it('requires a custom field the catalogue marks as required', () => {
    const schema = buildDeviceSchema(['sn'], columnsOf('sn'), [field('cost_center', true), field('rack')]);
    const result = schema.safeParse(model({ sn: 'SN-1' }, { cost_center: '', rack: '' }));

    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues).toHaveLength(1);
      expect(result.error.issues[0]?.path).toEqual(['custom', 'cost_center']);
    }
    expect(schema.safeParse(model({ sn: 'SN-1' }, { cost_center: 'CC-7', rack: '' })).success).toBe(true);
  });
});

describe('requireSn', () => {
  it('reads the published limit', () => {
    expect(requireSn({ limits: { requireSn: true } } as never)).toBe(true);
    expect(requireSn({ limits: { requireSn: false } } as never)).toBe(false);
  });
});
