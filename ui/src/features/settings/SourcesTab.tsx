/**
 * Sources tab — where assets come from.
 *
 * The list is server-ordered (system sources first) and carries the `usage`
 * count, so a refused delete is explained before the request instead of after:
 * a system source or one still used by assets has its delete button disabled.
 */

import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';

import { PanelApiError } from '@/api/client';
import { Alert, Button, Checkbox, Input, Spinner } from '@/components/ui';

import type { SettingsSource, SourceInput } from './api';
import { settingsErrorKey } from './settingsForm';
import { useSettingsSources, useSourceMutations } from './useSettings';

const TH = 'px-2 py-1 text-start text-xs font-semibold uppercase tracking-wide text-[var(--color-muted)]';
const TD = 'px-2 py-1 text-sm';

type FormState = { slug: string; label: string; color: string; is_default: boolean };

const EMPTY: FormState = { slug: '', label: '', color: '', is_default: false };

function formOf(source: SettingsSource): FormState {
  return {
    slug: source.slug,
    label: source.label,
    color: source.color ?? '',
    is_default: source.is_default,
  };
}

export function SourcesTab({ labelledBy }: { labelledBy: string }) {
  const { t } = useTranslation();
  const query = useSettingsSources();
  const { create, update, remove } = useSourceMutations();

  /** `null` = closed, `'new'` = create, anything else = the slug being edited. */
  const [editing, setEditing] = useState<string | null>(null);
  const [form, setForm] = useState<FormState>(EMPTY);

  const mutation = editing === 'new' ? create : update;
  const fieldErrors =
    mutation.error instanceof PanelApiError && mutation.error.isValidation ? mutation.error.errors : {};

  const open = (source: FormState | null) => {
    setEditing(source === null ? 'new' : source.slug);
    setForm(source ?? EMPTY);
    create.reset();
    update.reset();
  };

  const close = () => {
    setEditing(null);
    setForm(EMPTY);
  };

  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    const input: SourceInput = {
      slug: form.slug.trim(),
      label: form.label.trim(),
      color: form.color.trim() === '' ? null : form.color.trim(),
      is_default: form.is_default,
    };
    if (editing === 'new') {
      create.mutate(input, { onSuccess: close });
      return;
    }
    update.mutate(
      { slug: form.slug, input: { label: input.label, color: input.color, is_default: input.is_default } },
      { onSuccess: close },
    );
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

  return (
    <section aria-labelledby={labelledBy} className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2">
        <p className="m-0 text-sm text-[var(--color-muted)]">{t('set_sources_intro')}</p>
        <Button className="ms-auto" variant="primary" onClick={() => open(null)}>
          {t('set_source_add')}
        </Button>
      </div>

      {editing === null ? null : (
        <form onSubmit={onSubmit} className="card flex flex-col gap-3 p-4">
          <h3 className="m-0 text-sm font-semibold">
            {editing === 'new' ? t('set_source_add') : t('set_source_edit')}
          </h3>
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="flex flex-col gap-1">
              <label htmlFor="source-slug" className="text-xs font-medium text-[var(--color-muted)]">
                {t('set_source_slug')}
              </label>
              <Input
                id="source-slug"
                value={form.slug}
                // The slug is the provenance value stored on every asset, so it
                // cannot be renamed once the row exists.
                disabled={editing !== 'new'}
                onChange={(event) => setForm({ ...form, slug: event.target.value })}
              />
              <p className="m-0 text-xs text-[var(--color-muted)]">{t('set_source_slug_hint')}</p>
              {errorLine('slug')}
            </div>
            <div className="flex flex-col gap-1">
              <label htmlFor="source-label" className="text-xs font-medium text-[var(--color-muted)]">
                {t('set_source_label')}
              </label>
              <Input
                id="source-label"
                value={form.label}
                onChange={(event) => setForm({ ...form, label: event.target.value })}
              />
              {errorLine('label')}
            </div>
            <div className="flex flex-col gap-1">
              <label htmlFor="source-color" className="text-xs font-medium text-[var(--color-muted)]">
                {t('set_source_color')}
              </label>
              <Input
                id="source-color"
                value={form.color}
                placeholder="#4f46e5"
                onChange={(event) => setForm({ ...form, color: event.target.value })}
              />
              {errorLine('color')}
            </div>
            <div className="flex items-end">
              <Checkbox
                label={t('set_source_default_flag')}
                checked={form.is_default}
                onChange={(event) => setForm({ ...form, is_default: event.target.checked })}
              />
            </div>
          </div>
          <div className="flex items-center gap-2">
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
              <th className={TH}>{t('set_source_label')}</th>
              <th className={TH}>{t('set_source_slug')}</th>
              <th className={TH}>{t('set_source_color')}</th>
              <th className={TH}>{t('set_actions')}</th>
            </tr>
          </thead>
          <tbody>
            {(query.data?.sources ?? []).map((source) => {
              const blocked = source.is_system ? 'set_err_system_source' : source.usage > 0 ? 'set_err_in_use' : null;
              return (
                <tr key={source.slug} className="border-b border-[var(--color-border)] last:border-0">
                  <td className={TD}>
                    <span className="flex flex-wrap items-center gap-1.5">
                      {source.label}
                      {source.is_system ? (
                        <span className="text-xs text-[var(--color-muted)]">({t('set_source_system')})</span>
                      ) : null}
                      {source.is_default ? (
                        <span className="text-xs text-[var(--color-accent)]">({t('set_source_default_flag')})</span>
                      ) : null}
                    </span>
                  </td>
                  <td className={TD}>{source.slug}</td>
                  <td className={TD}>
                    <span className="flex items-center gap-1.5">
                      <span
                        aria-hidden="true"
                        className="inline-block size-3 rounded-full border border-[var(--color-border)]"
                        style={{ backgroundColor: source.color ?? 'transparent' }}
                      />
                      <span className="text-xs text-[var(--color-muted)]">{source.color ?? '—'}</span>
                    </span>
                  </td>
                  <td className={TD}>
                    <span className="flex flex-wrap items-center gap-1">
                      <Button variant="ghost" onClick={() => open(formOf(source))}>
                        {t('set_edit')}
                      </Button>
                      <Button
                        variant="ghost"
                        disabled={blocked !== null || remove.isPending}
                        title={blocked === null ? undefined : t(blocked)}
                        onClick={() => {
                          if (window.confirm(t('set_source_delete_confirm'))) {
                            remove.mutate(source.slug);
                          }
                        }}
                      >
                        {t('set_delete')}
                      </Button>
                      {source.usage > 0 ? (
                        <span className="text-xs text-[var(--color-muted)]">
                          {source.usage} {t('records')}
                        </span>
                      ) : null}
                    </span>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </section>
  );
}
