/**
 * Custom fields tab.
 *
 * Fields are EAV definitions: one row per field, values stored per asset. The
 * table shows the `usage` count so a delete that would drop stored values is an
 * informed decision, and the order buttons write the whole id list through
 * `POST /settings/fields/reorder`.
 */

import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';

import { PanelApiError } from '@/api/client';
import { Alert, Button, Checkbox, Input, Select, Spinner, Textarea } from '@/components/ui';

import type { FieldRow } from './api';
import { buildFieldInput, fieldFormFrom, moveField, settingsErrorKey, type FieldForm } from './settingsForm';
import { useFieldMutations, useSettingsFields } from './useSettings';

const TH = 'px-2 py-1 text-start text-xs font-semibold uppercase tracking-wide text-[var(--color-muted)]';
const TD = 'px-2 py-1 text-sm';

export function FieldsTab({ labelledBy }: { labelledBy: string }) {
  const { t } = useTranslation();
  const query = useSettingsFields();
  const { create, update, remove, reorder } = useFieldMutations();

  /** `null` = form closed, `'new'` = create, a row id = edit that field. */
  const [editing, setEditing] = useState<number | 'new' | null>(null);
  const [form, setForm] = useState<FieldForm>(() => fieldFormFrom(null, [], []));

  const fields = query.data?.fields ?? [];
  const types = query.data?.types ?? [];
  const roles = query.data?.roles ?? [];

  const mutation = editing === 'new' ? create : update;
  const fieldErrors =
    mutation.error instanceof PanelApiError && mutation.error.isValidation ? mutation.error.errors : {};

  const open = (row: FieldRow | null) => {
    setEditing(row === null ? 'new' : row.id);
    setForm(fieldFormFrom(row, types, roles));
    create.reset();
    update.reset();
  };

  const close = () => {
    setEditing(null);
    setForm(fieldFormFrom(null, types, roles));
  };

  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    const input = buildFieldInput(form);
    if (editing === 'new') {
      create.mutate(input, { onSuccess: close });
      return;
    }
    update.mutate({ id: editing as number, input }, { onSuccess: close });
  };

  if (query.isPending) {
    return (
      <div className="flex items-center gap-2 text-sm text-[var(--color-muted)]">
        <Spinner /> {t('ui_loading')}
      </div>
    );
  }

  if (query.isError) {
    return (
      <Alert
        title={t('ui_api_unavailable')}
        action={<Button onClick={() => void query.refetch()}>{t('ui_retry')}</Button>}
      >
        {query.error instanceof Error ? query.error.message : null}
      </Alert>
    );
  }

  const errorLine = (field: string) => {
    const code = fieldErrors[field];
    if (code === undefined) {
      return null;
    }
    const key = settingsErrorKey(code);
    return <p className="m-0 text-xs text-[var(--color-danger)]">{key === null ? code : t(key)}</p>;
  };

  const toggleRole = (role: string, checked: boolean) => {
    setForm((current) => ({
      ...current,
      roles: checked ? [...current.roles, role] : current.roles.filter((item) => item !== role),
    }));
  };

  return (
    <section aria-labelledby={labelledBy} className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2">
        <p className="m-0 text-sm text-[var(--color-muted)]">{t('set_fields_intro')}</p>
        <Button className="ms-auto" variant="primary" onClick={() => open(null)}>
          {t('set_field_add')}
        </Button>
      </div>

      {editing === null ? null : (
        <form onSubmit={onSubmit} className="card flex flex-col gap-3 p-4">
          <h3 className="m-0 text-sm font-semibold">
            {editing === 'new' ? t('set_field_add') : t('set_field_edit')}
          </h3>
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="flex flex-col gap-1">
              <label htmlFor="field-key" className="text-xs font-medium text-[var(--color-muted)]">
                {t('set_field_key')}
              </label>
              <Input
                id="field-key"
                value={form.key}
                // The key is the JSON member stored on every asset value row.
                disabled={editing !== 'new'}
                onChange={(event) => setForm({ ...form, key: event.target.value })}
              />
              <p className="m-0 text-xs text-[var(--color-muted)]">{t('set_field_key_hint')}</p>
              {errorLine('field_key')}
            </div>
            <div className="flex flex-col gap-1">
              <label htmlFor="field-label" className="text-xs font-medium text-[var(--color-muted)]">
                {t('set_field_label')}
              </label>
              <Input
                id="field-label"
                value={form.label}
                onChange={(event) => setForm({ ...form, label: event.target.value })}
              />
              {errorLine('label')}
            </div>
            <div className="flex flex-col gap-1">
              <label htmlFor="field-type" className="text-xs font-medium text-[var(--color-muted)]">
                {t('set_field_type')}
              </label>
              <Select
                id="field-type"
                value={form.type}
                onChange={(event) => setForm({ ...form, type: event.target.value })}
              >
                {types.map((type) => (
                  <option key={type.value} value={type.value}>
                    {type.label}
                  </option>
                ))}
              </Select>
              {errorLine('data_type')}
            </div>
            <div className="flex flex-col gap-1">
              <label htmlFor="field-default" className="text-xs font-medium text-[var(--color-muted)]">
                {t('set_field_default')}
              </label>
              <Input
                id="field-default"
                value={form.default_value}
                onChange={(event) => setForm({ ...form, default_value: event.target.value })}
              />
            </div>
            <div className="flex flex-col gap-1 sm:col-span-2">
              <label htmlFor="field-options" className="text-xs font-medium text-[var(--color-muted)]">
                {t('set_field_options')}
              </label>
              <Textarea
                id="field-options"
                rows={3}
                value={form.options}
                onChange={(event) => setForm({ ...form, options: event.target.value })}
              />
              <p className="m-0 text-xs text-[var(--color-muted)]">{t('set_field_options_hint')}</p>
              {errorLine('options_json')}
            </div>
          </div>

          <fieldset className="flex flex-col gap-2 border-0 p-0">
            <legend className="text-xs font-medium text-[var(--color-muted)]">{t('set_field_validation')}</legend>
            <div className="grid gap-3 sm:grid-cols-3">
              <div className="flex flex-col gap-1">
                <label htmlFor="field-min" className="text-xs text-[var(--color-muted)]">
                  {t('set_field_min')}
                </label>
                <Input
                  id="field-min"
                  type="number"
                  value={form.min}
                  onChange={(event) => setForm({ ...form, min: event.target.value })}
                />
              </div>
              <div className="flex flex-col gap-1">
                <label htmlFor="field-max" className="text-xs text-[var(--color-muted)]">
                  {t('set_field_max')}
                </label>
                <Input
                  id="field-max"
                  type="number"
                  value={form.max}
                  onChange={(event) => setForm({ ...form, max: event.target.value })}
                />
              </div>
              <div className="flex flex-col gap-1">
                <label htmlFor="field-pattern" className="text-xs text-[var(--color-muted)]">
                  {t('set_field_pattern')}
                </label>
                <Input
                  id="field-pattern"
                  value={form.pattern}
                  onChange={(event) => setForm({ ...form, pattern: event.target.value })}
                />
              </div>
            </div>
          </fieldset>

          <fieldset className="flex flex-col gap-2 border-0 p-0">
            <legend className="text-xs font-medium text-[var(--color-muted)]">{t('set_field_flags')}</legend>
            <div className="flex flex-wrap gap-3">
              <Checkbox
                label={t('set_field_required')}
                checked={form.required}
                onChange={(event) => setForm({ ...form, required: event.target.checked })}
              />
              <Checkbox
                label={t('set_field_unique')}
                checked={form.unique}
                onChange={(event) => setForm({ ...form, unique: event.target.checked })}
              />
              <Checkbox
                label={t('set_field_searchable')}
                checked={form.searchable}
                onChange={(event) => setForm({ ...form, searchable: event.target.checked })}
              />
              <Checkbox
                label={t('set_field_grid')}
                checked={form.in_grid}
                onChange={(event) => setForm({ ...form, in_grid: event.target.checked })}
              />
              <Checkbox
                label={t('set_field_active')}
                checked={form.active}
                onChange={(event) => setForm({ ...form, active: event.target.checked })}
              />
            </div>
          </fieldset>

          <fieldset className="flex flex-col gap-2 border-0 p-0">
            <legend className="text-xs font-medium text-[var(--color-muted)]">{t('set_field_roles')}</legend>
            <div className="flex flex-wrap gap-3">
              {roles.map((role) => (
                <Checkbox
                  key={role}
                  label={t(`role_${role}`)}
                  checked={form.roles.includes(role)}
                  onChange={(event) => toggleRole(role, event.target.checked)}
                />
              ))}
            </div>
            {errorLine('editable_roles')}
          </fieldset>

          <div className="flex flex-wrap items-center gap-2">
            <Button type="submit" variant="primary" disabled={mutation.isPending}>
              {t('save_changes')}
            </Button>
            <Button variant="ghost" onClick={close}>
              {t('cancel')}
            </Button>
            {mutation.isPending ? <Spinner /> : null}
          </div>
        </form>
      )}

      <div className="card overflow-x-auto p-0">
        <table className="w-full border-collapse">
          <thead>
            <tr className="border-b border-[var(--color-border)]">
              <th className={TH}>{t('set_field_key')}</th>
              <th className={TH}>{t('set_field_label')}</th>
              <th className={TH}>{t('set_field_type')}</th>
              <th className={TH}>{t('set_field_sort')}</th>
              <th className={TH}>{t('set_actions')}</th>
            </tr>
          </thead>
          <tbody>
            {fields.length === 0 ? (
              <tr>
                <td className={TD} colSpan={5}>
                  {t('set_field_none')}
                </td>
              </tr>
            ) : null}
            {fields.map((field, index) => (
              <tr key={field.id} className="border-b border-[var(--color-border)] last:border-0">
                <td className={TD}>
                  <span className="flex flex-wrap items-center gap-1.5">
                    <code className="text-xs">{field.key}</code>
                    {field.active ? null : (
                      <span className="text-xs text-[var(--color-muted)]">({t('set_field_deactivate')})</span>
                    )}
                  </span>
                </td>
                <td className={TD}>{field.label}</td>
                <td className={TD}>{field.type}</td>
                <td className={TD}>{field.sort_order}</td>
                <td className={TD}>
                  <span className="flex flex-wrap items-center gap-1">
                    <Button variant="ghost" onClick={() => open(field)}>
                      {t('set_edit')}
                    </Button>
                    <Button
                      variant="ghost"
                      aria-label={t('ui_move_up', { name: field.label })}
                      disabled={index === 0 || reorder.isPending}
                      onClick={() => reorder.mutate(moveField(fields, field.id, -1))}
                    >
                      ↑
                    </Button>
                    <Button
                      variant="ghost"
                      aria-label={t('ui_move_down', { name: field.label })}
                      disabled={index === fields.length - 1 || reorder.isPending}
                      onClick={() => reorder.mutate(moveField(fields, field.id, 1))}
                    >
                      ↓
                    </Button>
                    <Button
                      variant="ghost"
                      disabled={remove.isPending}
                      title={field.usage > 0 ? `${field.usage} ${t('records')}` : undefined}
                      onClick={() => {
                        if (window.confirm(t('set_field_delete_confirm'))) {
                          remove.mutate(field.id);
                        }
                      }}
                    >
                      {t('set_delete')}
                    </Button>
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
