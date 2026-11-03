/**
 * API keys tab.
 *
 * A token is returned exactly once (`POST` and `rotate`), so the panel above the
 * table is the only place it is ever visible — closing it drops it for good, and
 * the table only ever shows the prefix.
 */

import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';

import { PanelApiError } from '@/api/client';
import { Alert, Button, Checkbox, Input, Select, Spinner, Textarea } from '@/components/ui';

import type { ApiKeyRow } from './api';
import { apiKeyFormFrom, buildApiKeyInput, settingsErrorKey, type ApiKeyForm } from './settingsForm';
import { useApiKeyMutations, useApiKeys } from './useSettings';

const TH = 'px-2 py-1 text-start text-xs font-semibold uppercase tracking-wide text-[var(--color-muted)]';
const TD = 'px-2 py-1 text-sm';

export function ApiKeysTab({ labelledBy }: { labelledBy: string }) {
  const { t } = useTranslation();
  const query = useApiKeys();
  const { create, update, rotate, remove } = useApiKeyMutations();

  /** `null` = form closed, `'new'` = create, a row id = edit that key. */
  const [editing, setEditing] = useState<number | 'new' | null>(null);
  const [form, setForm] = useState<ApiKeyForm>(() => apiKeyFormFrom(null));
  const [token, setToken] = useState('');

  const sources = query.data?.sources ?? [];
  const mutation = editing === 'new' ? create : update;
  const fieldErrors =
    mutation.error instanceof PanelApiError && mutation.error.isValidation ? mutation.error.errors : {};

  const open = (row: ApiKeyRow | null) => {
    setEditing(row === null ? 'new' : row.id);
    setForm(apiKeyFormFrom(row));
    create.reset();
    update.reset();
  };

  const close = () => {
    setEditing(null);
    setForm(apiKeyFormFrom(null));
    create.reset();
    update.reset();
  };

  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    const input = buildApiKeyInput(form);
    if (editing === 'new') {
      create.mutate(input, {
        onSuccess: (created) => {
          setToken(created.token);
          close();
        },
      });
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

  const toggleActive = (row: ApiKeyRow) => {
    update.mutate({ id: row.id, input: { ...apiKeyFormFrom(row), is_active: !row.is_active } });
  };

  return (
    <section aria-labelledby={labelledBy} className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2">
        <p className="m-0 text-sm text-[var(--color-muted)]">{t('set_api_intro')}</p>
        <Button className="ms-auto" variant="primary" onClick={() => open(null)}>
          {t('set_key_create')}
        </Button>
      </div>

      {token === '' ? null : (
        <Alert
          tone="warning"
          title={t('set_key_token_once')}
          action={
            <span className="flex flex-wrap items-center gap-2">
              <code className="break-all rounded-[var(--radius-md)] bg-[var(--color-surface-2)] px-2 py-1 text-xs">
                {token}
              </code>
              <Button variant="ghost" onClick={() => setToken('')}>
                {t('set_actions')}
              </Button>
            </span>
          }
        >
          {t('set_key_token_warning')}
        </Alert>
      )}

      {editing === null ? null : (
        <form onSubmit={onSubmit} className="card flex flex-col gap-3 p-4">
          <h3 className="m-0 text-sm font-semibold">
            {editing === 'new' ? t('set_key_create') : t('set_key_edit')}
          </h3>
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="flex flex-col gap-1">
              <label htmlFor="key-label" className="text-xs font-medium text-[var(--color-muted)]">
                {t('set_key_label')}
              </label>
              <Input
                id="key-label"
                value={form.label}
                onChange={(event) => setForm({ ...form, label: event.target.value })}
              />
              {errorLine('label')}
            </div>
            <div className="flex flex-col gap-1">
              <label htmlFor="key-company" className="text-xs font-medium text-[var(--color-muted)]">
                {t('set_key_company')}
              </label>
              <Input
                id="key-company"
                value={form.company}
                placeholder={t('set_key_global')}
                onChange={(event) => setForm({ ...form, company: event.target.value })}
              />
              {errorLine('company')}
            </div>
            <div className="flex flex-col gap-1">
              <label htmlFor="key-source" className="text-xs font-medium text-[var(--color-muted)]">
                {t('set_key_source')}
              </label>
              <Select
                id="key-source"
                value={form.source_slug}
                onChange={(event) => setForm({ ...form, source_slug: event.target.value })}
              >
                <option value="">{t('set_key_default_source')}</option>
                {sources.map((source) => (
                  <option key={source.slug} value={source.slug}>
                    {source.label}
                  </option>
                ))}
              </Select>
              {errorLine('source_slug')}
            </div>
            <div className="flex flex-col gap-1">
              <label htmlFor="key-allowed" className="text-xs font-medium text-[var(--color-muted)]">
                {t('set_key_allowed_sources')}
              </label>
              <Input
                id="key-allowed"
                value={form.allowed_sources}
                onChange={(event) => setForm({ ...form, allowed_sources: event.target.value })}
              />
              <p className="m-0 text-xs text-[var(--color-muted)]">{t('set_key_allowed_sources_hint')}</p>
              {errorLine('allowed_sources')}
            </div>
            <div className="flex flex-col gap-1 sm:col-span-2">
              <label htmlFor="key-allowlist" className="text-xs font-medium text-[var(--color-muted)]">
                {t('set_key_allowlist')}
              </label>
              <Textarea
                id="key-allowlist"
                rows={2}
                value={form.ip_allowlist}
                placeholder={t('set_allowlist_hint')}
                onChange={(event) => setForm({ ...form, ip_allowlist: event.target.value })}
              />
              {errorLine('ip_allowlist')}
            </div>
          </div>

          <fieldset className="flex flex-col gap-2 border-0 p-0">
            <legend className="text-xs font-medium text-[var(--color-muted)]">{t('set_key_scopes')}</legend>
            <div className="flex flex-wrap gap-3">
              <Checkbox
                label={t('set_key_read')}
                checked={form.can_read}
                onChange={(event) => setForm({ ...form, can_read: event.target.checked })}
              />
              <Checkbox
                label={t('set_key_write')}
                checked={form.can_write}
                onChange={(event) => setForm({ ...form, can_write: event.target.checked })}
              />
              <Checkbox
                label={t('set_delete')}
                checked={form.can_delete}
                onChange={(event) => setForm({ ...form, can_delete: event.target.checked })}
              />
              <Checkbox
                label={t('set_key_active')}
                checked={form.is_active}
                onChange={(event) => setForm({ ...form, is_active: event.target.checked })}
              />
            </div>
            {errorLine('can_read')}
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
              <th className={TH}>{t('set_key_label')}</th>
              <th className={TH}>{t('set_key_prefix')}</th>
              <th className={TH}>{t('set_key_scopes')}</th>
              <th className={TH}>{t('set_key_last_used')}</th>
              <th className={TH}>{t('set_actions')}</th>
            </tr>
          </thead>
          <tbody>
            {(query.data?.keys ?? []).length === 0 ? (
              <tr>
                <td className={TD} colSpan={5}>
                  {t('set_key_none')}
                </td>
              </tr>
            ) : null}
            {(query.data?.keys ?? []).map((row) => (
              <tr key={row.id} className="border-b border-[var(--color-border)] last:border-0">
                <td className={TD}>
                  <span className="flex flex-wrap items-center gap-1.5">
                    {row.label}
                    <span className="text-xs text-[var(--color-muted)]">
                      {row.is_active ? `(${t('set_key_enabled')})` : `(${t('set_key_disabled')})`}
                    </span>
                    {row.company === null ? null : (
                      <span className="text-xs text-[var(--color-muted)]">· {row.company}</span>
                    )}
                  </span>
                </td>
                <td className={TD}>
                  <code className="text-xs">{row.prefix}</code>
                </td>
                <td className={TD}>
                  <span className="text-xs">
                    {[
                      row.can_read ? t('set_key_read') : null,
                      row.can_write ? t('set_key_write') : null,
                      row.can_delete ? t('set_delete') : null,
                    ]
                      .filter((item): item is string => item !== null)
                      .join(' · ')}
                  </span>
                </td>
                <td className={TD}>
                  <span className="text-xs text-[var(--color-muted)]">
                    {row.last_used_at === null ? t('set_key_never') : row.last_used_at}
                  </span>
                </td>
                <td className={TD}>
                  <span className="flex flex-wrap items-center gap-1">
                    <Button variant="ghost" onClick={() => open(row)}>
                      {t('set_edit')}
                    </Button>
                    <Button
                      variant="ghost"
                      disabled={rotate.isPending}
                      onClick={() => {
                        if (!window.confirm(t('set_key_rotate_confirm'))) {
                          return;
                        }
                        rotate.mutate(row.id, { onSuccess: (created) => setToken(created.token) });
                      }}
                    >
                      {t('set_key_rotate')}
                    </Button>
                    <Button variant="ghost" disabled={update.isPending} onClick={() => toggleActive(row)}>
                      {row.is_active ? t('set_key_disable') : t('set_key_enable')}
                    </Button>
                    <Button
                      variant="ghost"
                      disabled={remove.isPending}
                      onClick={() => {
                        if (window.confirm(t('set_key_delete_confirm'))) {
                          remove.mutate(row.id);
                        }
                      }}
                    >
                      {t('set_key_delete')}
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
