import { describe, expect, it } from 'vitest';

import type { DeviceDetail, MetaPayload } from './api';
import { buildDevicePayload, formFields, formKeys, initialFormValues, normalizeFieldErrors } from './deviceForm';

const meta = {
  columns: [
    { key: 'SN', api_key: 'sn', label_key: 'col_sn', label: 'SN', type: 'text', in_grid: true, sortable: true, editable: true, virtual: false },
    { key: 'LastSeen', api_key: 'last_seen', label_key: 'col_last_seen', label: 'Last seen', type: 'datetime', in_grid: false, sortable: false, editable: false, virtual: false },
    { key: 'BYOD', api_key: 'byod', label_key: 'col_byod', label: 'BYOD', type: 'bool', in_grid: true, sortable: true, editable: true, virtual: false },
    { key: 'source', api_key: 'source', label_key: 'col_source', label: 'Source', type: 'text', in_grid: true, sortable: true, editable: false, virtual: false },
    { key: 'files', api_key: 'files_count', label_key: 'col_files', label: 'Files', type: 'int', in_grid: true, sortable: true, editable: false, virtual: true },
  ],
  fields: [
    { id: 1, key: 'cost_center', label: 'Cost center', label_i18n: null, type: 'text', options: [], required: false, unique: false, searchable: true, in_grid: true, editable_roles: ['manager'], sort_order: 0 },
    { id: 2, key: 'admin_only', label: 'Admin only', label_i18n: null, type: 'text', options: [], required: false, unique: false, searchable: false, in_grid: false, editable_roles: ['admin'], sort_order: 1 },
  ],
  writeColumns: { create: ['sn', 'byod', 'source'], update: ['sn', 'byod'] },
} as unknown as MetaPayload;

const device = {
  id: 7,
  sn: 'SN-7',
  byod: true,
  last_seen: '2026-11-02T13:45:00Z',
  source: 'eset',
  custom: { cost_center: 'CC-1' },
} as unknown as DeviceDetail;

describe('formKeys / formFields', () => {
  it('uses the create list in create mode and ignores unknown keys', () => {
    expect(formKeys(meta, 'create')).toEqual(['sn', 'byod', 'source']);
  });

  it('filters custom fields by role', () => {
    expect(formFields(meta, 'manager').map((field) => field.key)).toEqual(['cost_center']);
    expect(formFields(meta, 'admin').map((field) => field.key)).toEqual(['admin_only']);
    expect(formFields(meta, 'user')).toEqual([]);
  });
});

describe('initialFormValues', () => {
  it('keeps booleans and trims datetime values for the input', () => {
    const { values } = initialFormValues(['sn', 'byod', 'last_seen'], device, [], { last_seen: 'datetime' });
    expect(values.sn).toBe('SN-7');
    expect(values.byod).toBe(true);
    expect(values.last_seen).toBe('2026-11-02T13:45');
  });

  it('reads custom values and defaults missing ones', () => {
    const { custom } = initialFormValues([], device, formFields(meta, 'manager'));
    expect(custom.cost_center).toBe('CC-1');

    const admin = initialFormValues([], device, formFields(meta, 'admin'));
    expect(admin.custom.cost_center).toBeUndefined();
    expect(admin.custom.admin_only).toBe('');
  });

  it('produces empty values for a new device', () => {
    const { values } = initialFormValues(['sn', 'byod'], null, []);
    expect(values).toEqual({ sn: '', byod: '' });
  });
});

describe('buildDevicePayload', () => {
  it('sends nulls for cleared text and keeps booleans', () => {
    const body = buildDevicePayload(
      { sn: '  SN-9  ', byod: false },
      { cost_center: '' },
      formFields(meta, 'manager'),
    );
    expect(body.sn).toBe('SN-9');
    expect(body.byod).toBe(false);
    expect(body.custom).toEqual({ cost_center: null });
  });

  it('omits the custom block when no field is rendered', () => {
    const body = buildDevicePayload({ sn: 'X' }, {}, []);
    expect('custom' in body).toBe(false);
  });
});

describe('normalizeFieldErrors', () => {
  it('keeps snake_case keys and custom prefixes', () => {
    expect(normalizeFieldErrors({ sn: 'duplicate', 'custom.cost_center': 'invalid' })).toEqual({
      sn: 'duplicate',
      'custom.cost_center': 'invalid',
    });
  });
});
