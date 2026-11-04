import { describe, expect, it } from 'vitest';

import type { ApiKeyRow, FieldRow, SettingEntry } from './api';
import {
  apiKeyFormFrom,
  buildApiKeyInput,
  buildFieldInput,
  buildSettingReset,
  buildSettingsPatch,
  fieldFormFrom,
  moveField,
  parseOptions,
  settingsErrorKey,
  settingsFormFrom,
  sortSettings,
} from './settingsForm';

function setting(overrides: Partial<SettingEntry> & { key: string }): SettingEntry {
  return {
    group: 'storage',
    label: overrides.key,
    label_key: `setting_${overrides.key.replace(/\./g, '_')}`,
    type: 'text',
    options: [],
    min: null,
    max: null,
    source: 'db',
    secret: false,
    configured: null,
    value: '',
    default: '',
    placeholders: {},
    ...overrides,
  };
}

const SECRET = setting({
  key: 's3.secret_key',
  type: 'secret',
  secret: true,
  configured: true,
  value: '',
});
const BUCKET = setting({ key: 's3.bucket', value: 'cmdb' });
const PATH_STYLE = setting({ key: 's3.path_style', type: 'bool', value: '1' });

describe('buildSettingsPatch', () => {
  it('never sends an untouched secret, so a save cannot wipe it', () => {
    const entries = [BUCKET, SECRET];
    const patch = buildSettingsPatch(entries, settingsFormFrom(entries));

    expect(patch.values).toEqual({ 's3.bucket': 'cmdb' });
    expect(patch.values['s3.secret_key']).toBeUndefined();
    expect(patch.clear).toBeUndefined();
  });

  it('sends a new secret value when the field has text', () => {
    const entries = [SECRET];
    const form = { ...settingsFormFrom(entries), values: { 's3.secret_key': ' super-secret ' } };

    expect(buildSettingsPatch(entries, form).values).toEqual({ 's3.secret_key': ' super-secret ' });
  });

  it('wipes a stored secret through `clear` instead of an empty value', () => {
    const entries = [SECRET];
    const form = { ...settingsFormFrom(entries), clears: { 's3.secret_key': true } };

    const patch = buildSettingsPatch(entries, form);
    expect(patch.values).toEqual({});
    expect(patch.clear).toEqual(['s3.secret_key']);
  });

  it('normalises a boolean setting to the 1/0 the server parses', () => {
    const entries = [PATH_STYLE];
    const form = { ...settingsFormFrom(entries), values: { 's3.path_style': '0' } };

    expect(buildSettingsPatch(entries, form).values['s3.path_style']).toBe('0');
  });
});

describe('buildSettingReset', () => {
  const PREFIX = setting({
    key: 's3.prefix',
    default: '{{company}}-{{asset}}-',
    placeholders: { company: 'Company', asset: 'Asset tag' },
    value: 'old/',
  });

  it('sends the rest of the form plus the key to reset, in that order', () => {
    const entries = [BUCKET, PREFIX];
    const form = settingsFormFrom(entries);
    const body = buildSettingReset(entries, form, 's3.prefix');

    // The values ride along so the re-seed cannot discard edits in other cards.
    expect(body.values).toEqual({ 's3.bucket': 'cmdb', 's3.prefix': 'old/' });
    expect(body.reset).toEqual(['s3.prefix']);
  });

  it('carries the current edits of the other settings', () => {
    const entries = [BUCKET, PREFIX];
    const form = { ...settingsFormFrom(entries), values: { 's3.bucket': 'elsewhere', 's3.prefix': 'old/' } };

    expect(buildSettingReset(entries, form, 's3.prefix').values['s3.bucket']).toBe('elsewhere');
  });

  it('never sends a secret through a reset', () => {
    const entries = [SECRET, PREFIX];
    const form = settingsFormFrom(entries);

    const body = buildSettingReset(entries, form, 's3.prefix');
    expect(body.values['s3.secret_key']).toBeUndefined();
    expect(body.clear).toBeUndefined();
  });
});

describe('sortSettings', () => {
  it('puts booleans first while keeping the server order inside each block', () => {
    const entries = [
      setting({ key: 's3.region' }),
      setting({ key: 's3.path_style', type: 'bool' }),
      setting({ key: 's3.bucket' }),
      setting({ key: 's3.presign_seconds', type: 'bool' }),
    ];

    expect(sortSettings(entries).map((entry) => entry.key)).toEqual([
      's3.path_style',
      's3.presign_seconds',
      's3.region',
      's3.bucket',
    ]);
  });
});

describe('settingsErrorKey', () => {
  it('maps a server validation code to a catalogue key', () => {
    expect(settingsErrorKey('required')).toBe('set_err_required');
    expect(settingsErrorKey('in_use')).toBe('set_err_in_use');
    expect(settingsErrorKey('self_role_change')).toBe('ui_err_forbidden');
  });

  it('returns null for a code without a translation so it is shown verbatim', () => {
    expect(settingsErrorKey('something_new')).toBeNull();
  });
});

const FIELD: FieldRow = {
  id: 7,
  key: 'warranty_type',
  label: 'Warranty type',
  label_i18n: {},
  type: 'select',
  options: ['oem', 'extended'],
  default: 'oem',
  validation: { min: null, max: null, pattern: null },
  required: false,
  unique: false,
  searchable: true,
  in_grid: false,
  editable_roles: ['manager', 'admin'],
  sort_order: 3,
  active: true,
  usage: 0,
};

describe('custom field form', () => {
  it('builds the short keys `panel_settings_field_apply()` understands', () => {
    const form = { ...fieldFormFrom(FIELD, [], []), min: '1', max: '10', pattern: '^[a-z]+$' };
    const input = buildFieldInput(form);

    expect(input.key).toBe('warranty_type');
    expect(input.type).toBe('select');
    expect(input.options).toEqual(['oem', 'extended']);
    expect(input.validation).toEqual({ min: 1, max: 10, pattern: '^[a-z]+$' });
    expect(Object.keys(input)).not.toContain('field_key');
    expect(Object.keys(input)).not.toContain('data_type');
  });

  it('drops an empty validation rule instead of sending a null', () => {
    const input = buildFieldInput(fieldFormFrom(FIELD, [], []));
    expect(input.validation).toEqual({});
  });

  it('splits options on newlines and trims blanks', () => {
    expect(parseOptions(' oem \n\nextended\n')).toEqual(['oem', 'extended']);
  });

  it('reorders ids and clamps at both ends', () => {
    const fields = [FIELD, { ...FIELD, id: 8 }, { ...FIELD, id: 9 }];
    expect(moveField(fields, 8, -1)).toEqual([8, 7, 9]);
    expect(moveField(fields, 7, -1)).toEqual([7, 8, 9]);
    expect(moveField(fields, 9, 1)).toEqual([7, 8, 9]);
  });
});

describe('api key form', () => {
  it('defaults a new key to read + write and starts blank', () => {
    const form = apiKeyFormFrom(null);
    expect(form).toMatchObject({ label: '', can_read: true, can_write: true, can_delete: false, can_users: false });
  });

  it('trims text fields and joins the allowed sources', () => {
    const row: ApiKeyRow = {
      id: 1,
      label: ' Intune ',
      prefix: 'abcd1234',
      company: null,
      source_slug: 'intune',
      allowed_sources: ['intune', 'manual'],
      ip_allowlist: ' 10.0.0.0/8 ',
      can_read: true,
      can_write: false,
      can_delete: false,
      can_users: true,
      is_active: true,
      created_at: null,
      created_by: null,
      rotated_at: null,
      last_used_at: null,
      last_used_ip: null,
    };

    const input = buildApiKeyInput({ ...apiKeyFormFrom(row), label: ' Intune ' });
    expect(input.label).toBe('Intune');
    expect(input.company).toBe('');
    expect(input.ip_allowlist).toBe('10.0.0.0/8');
    expect(apiKeyFormFrom(row).allowed_sources).toBe('intune,manual');
    expect(input.can_users).toBe(true);
  });
});
