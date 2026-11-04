/**
 * Form state and request bodies for the settings screens.
 *
 * Kept out of the components so the two rules that are easy to get wrong can be
 * unit-tested without a DOM or a fetch mock:
 *
 *  - a secret is only written when the field has text; an untouched password
 *    input must not erase a working S3 key, and wiping one is an explicit
 *    `{"clear": [...]}`;
 *  - a boolean setting travels as `"1"`/`"0"`, which is what
 *    `settings_normalize()` accepts (a real `true` used to arrive as `"1"` and a
 *    `false` as `""`).
 */

import type {
  ApiKeyInput,
  ApiKeyRow,
  FieldInput,
  FieldRow,
  FieldRules,
  FieldType,
  SettingEntry,
  SettingsPatchBody,
} from './api';

export type SettingsForm = {
  values: Record<string, string>;
  /** Secrets the user asked to wipe — sent as `clear`, never as an empty value. */
  clears: Record<string, boolean>;
};

/** Initial form state; secrets start blank because the API never sends them. */
export function settingsFormFrom(entries: SettingEntry[]): SettingsForm {
  const values: Record<string, string> = {};
  for (const entry of entries) {
    values[entry.key] = entry.secret ? '' : entry.value;
  }
  return { values, clears: {} };
}

/** Booleans first in every card; a stable sort keeps the server order inside each block. */
export function sortSettings(entries: SettingEntry[]): SettingEntry[] {
  return [...entries].sort((a, b) => Number(b.type === 'bool') - Number(a.type === 'bool'));
}

/** `PATCH /settings` body for the current form state. */
export function buildSettingsPatch(entries: SettingEntry[], form: SettingsForm): SettingsPatchBody {
  const values: Record<string, string> = {};
  const clear: string[] = [];

  for (const entry of entries) {
    const raw = form.values[entry.key] ?? '';

    if (entry.secret) {
      if (form.clears[entry.key] === true) {
        clear.push(entry.key);
        continue;
      }
      // Blank means "unchanged": the stored value was never in the form.
      if (raw.trim() === '') {
        continue;
      }
      values[entry.key] = raw;
      continue;
    }

    values[entry.key] = entry.type === 'bool' ? (raw === '1' ? '1' : '0') : raw;
  }

  return clear.length === 0 ? { values } : { values, clear };
}

/**
 * `PATCH /settings` body for the "Reset to default" button.
 *
 * The rest of the form rides along on purpose: dropping the DB row re-seeds
 * every field from the response, so a reset that silently threw away edits made
 * in other cards would be worse than the reset itself. `values` and `reset`
 * travel together because the server applies `values` first and `reset` last —
 * the reset always wins for its own key.
 */
export function buildSettingReset(entries: SettingEntry[], form: SettingsForm, key: string): SettingsPatchBody {
  return { ...buildSettingsPatch(entries, form), reset: [key] };
}

/**
 * Validation code → catalogue key.
 *
 * Unknown codes are returned as `null` and shown verbatim, which is what the
 * device form does: a new server-side code must still be visible to the user.
 */
const ERROR_KEYS: Record<string, string> = {
  required: 'set_err_required',
  too_long: 'set_err_too_long',
  duplicate: 'set_err_duplicate',
  in_use: 'set_err_in_use',
  out_of_range: 'set_err_out_of_range',
  invalid_option: 'set_err_invalid_option',
  invalid_number: 'set_err_invalid_number',
  invalid_timezone: 'set_err_invalid_timezone',
  invalid_locale: 'set_err_invalid_locale',
  invalid_role: 'set_err_invalid_role',
  invalid_type: 'set_err_invalid_type',
  invalid_color: 'set_err_invalid_color',
  invalid_ip: 'set_err_invalid_ip',
  invalid_placeholder: 'set_err_invalid_placeholder',
  invalid_key: 'set_err_invalid_key',
  invalid_slug: 'set_err_invalid_key',
  system_source: 'set_err_system_source',
  unknown_setting: 'set_err_unknown_setting',
  unknown_source: 'set_err_unknown_source',
  unknown_field: 'set_err_unknown_field',
  options_required: 'set_err_options_required',
  no_scope: 'set_err_no_scope',
  default_not_allowed: 'set_err_default_not_allowed',
  not_a_secret: 'set_err_invalid_key',
  not_found: 'set_err_unknown_field',
  self_role_change: 'ui_err_forbidden',
  forbidden: 'ui_err_forbidden',
};

export function settingsErrorKey(code: string): string | null {
  return ERROR_KEYS[code] ?? null;
}

// --- Custom field form -----------------------------------------------------

/** Everything the field drawer edits, as strings (an empty number is "unset"). */
export type FieldForm = {
  key: string;
  label: string;
  type: string;
  options: string;
  default_value: string;
  min: string;
  max: string;
  pattern: string;
  sort_order: string;
  required: boolean;
  unique: boolean;
  searchable: boolean;
  in_grid: boolean;
  active: boolean;
  roles: string[];
};

export function fieldFormFrom(row: FieldRow | null, types: FieldType[], roles: string[]): FieldForm {
  if (row === null) {
    const fallbackType = types[0]?.value ?? 'text';
    const fallbackRole = roles.includes('manager') ? ['manager'] : roles.slice(0, 1);
    return {
      key: '',
      label: '',
      type: fallbackType,
      options: '',
      default_value: '',
      min: '',
      max: '',
      pattern: '',
      sort_order: '0',
      required: false,
      unique: false,
      searchable: false,
      in_grid: false,
      active: true,
      roles: fallbackRole,
    };
  }

  return {
    key: row.key,
    label: row.label,
    type: row.type,
    options: row.options.join('\n'),
    default_value: row.default,
    min: row.validation.min === null || row.validation.min === undefined ? '' : String(row.validation.min),
    max: row.validation.max === null || row.validation.max === undefined ? '' : String(row.validation.max),
    pattern: row.validation.pattern ?? '',
    sort_order: String(row.sort_order),
    required: row.required,
    unique: row.unique,
    searchable: row.searchable,
    in_grid: row.in_grid,
    active: row.active,
    roles: row.editable_roles,
  };
}

/** One option per line, blanks dropped — the same rule the legacy textarea uses. */
export function parseOptions(text: string): string[] {
  return text
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '');
}

function numberOrNull(text: string): number | null {
  const trimmed = text.trim();
  if (trimmed === '') {
    return null;
  }
  const value = Number(trimmed);
  return Number.isFinite(value) ? value : null;
}

export function buildFieldInput(form: FieldForm): FieldInput {
  const rules: FieldRules = {};
  const min = numberOrNull(form.min);
  const max = numberOrNull(form.max);
  const pattern = form.pattern.trim();
  if (min !== null) {
    rules.min = min;
  }
  if (max !== null) {
    rules.max = max;
  }
  if (pattern !== '') {
    rules.pattern = pattern;
  }

  return {
    key: form.key.trim(),
    label: form.label.trim(),
    type: form.type,
    options: parseOptions(form.options),
    default: form.default_value,
    validation: rules,
    required: form.required,
    unique: form.unique,
    searchable: form.searchable,
    in_grid: form.in_grid,
    editable_roles: form.roles,
    sort_order: numberOrNull(form.sort_order) ?? 0,
    active: form.active,
  };
}

/** New id order after moving one field up (`-1`) or down (`+1`). */
export function moveField(fields: FieldRow[], id: number, delta: -1 | 1): number[] {
  const order = fields.map((field) => field.id);
  const from = order.indexOf(id);
  const to = from + delta;
  if (from < 0 || to < 0 || to >= order.length) {
    return order;
  }
  order.splice(to, 0, ...order.splice(from, 1));
  return order;
}

// --- API key form ----------------------------------------------------------

export type ApiKeyForm = ApiKeyInput;

export function apiKeyFormFrom(row: ApiKeyRow | null): ApiKeyForm {
  if (row === null) {
    return {
      label: '',
      company: '',
      source_slug: '',
      allowed_sources: '',
      ip_allowlist: '',
      can_read: true,
      can_write: true,
      can_delete: false,
      can_users: false,
      is_active: true,
    };
  }
  return {
    label: row.label,
    company: row.company ?? '',
    source_slug: row.source_slug ?? '',
    allowed_sources: row.allowed_sources.join(','),
    ip_allowlist: row.ip_allowlist,
    can_read: row.can_read,
    can_write: row.can_write,
    can_delete: row.can_delete,
    can_users: row.can_users,
    is_active: row.is_active,
  };
}

/** Trim the text inputs; the server validates the rest (IPs, source slugs). */
export function buildApiKeyInput(form: ApiKeyForm): ApiKeyInput {
  return {
    label: form.label.trim(),
    company: form.company.trim(),
    source_slug: form.source_slug,
    allowed_sources: form.allowed_sources.trim(),
    ip_allowlist: form.ip_allowlist.trim(),
    can_read: form.can_read,
    can_write: form.can_write,
    can_delete: form.can_delete,
    can_users: form.can_users,
    is_active: form.is_active,
  };
}
