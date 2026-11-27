/**
 * Categories tab — the controlled vocabulary behind `inventory_items.category`.
 *
 * The registry is per kind, so the tab renders one table per tab of the app
 * (Assets, Systems) instead of one mixed list: a name only ever appears in the
 * form of its own kind, and moving a name between kinds is a create + delete,
 * not a rename.
 *
 * Deleting is the interesting action. An item always has a category, so the
 * delete of a row that still has items asks for the row that receives them (a
 * reassign panel, like the sources tab), and the last row of a kind has its
 * delete button disabled — without any vocabulary left the required field could
 * never be satisfied again.
 */

import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';

import { PanelApiError } from '@/api/client';
import { Alert, Button, Input, Select, Spinner } from '@/components/ui';
import { inventoryListKey } from '@/features/inventory/api';

import type { CategoryKind, CategoryRow } from './api';
import { settingsErrorKey } from './settingsForm';
import { useCategories, useCategoryMutations } from './useSettings';

const TH = 'px-2 py-1 text-start text-xs font-semibold uppercase tracking-wide text-[var(--color-muted)]';
const TD = 'px-2 py-1 text-sm';

export function CategoriesTab({ labelledBy }: { labelledBy: string }) {
  const { t } = useTranslation();
  const query = useCategories(true);
  const { create, update, remove } = useCategoryMutations();

  /** `null` = closed, `'new'` = create, a number = the id being renamed. */
  const [editing, setEditing] = useState<'new' | number | null>(null);
  const [form, setForm] = useState<{ kind: CategoryKind; name: string }>({ kind: 'asset', name: '' });
  /** Category being drained of its items; `null` closes the reassign panel. */
  const [deleting, setDeleting] = useState<CategoryRow | null>(null);
  const [target, setTarget] = useState('');

  const mutation = editing === 'new' ? create : update;
  const fieldErrors =
    mutation.error instanceof PanelApiError && mutation.error.isValidation ? mutation.error.errors : {};

  const categories = query.data?.categories ?? [];
  const kinds = query.data?.kinds ?? ['asset', 'system'];

  const openCreate = () => {
    create.reset();
    update.reset();
    setEditing('new');
    setForm({ kind: 'asset', name: '' });
  };

  const openRename = (row: CategoryRow) => {
    create.reset();
    update.reset();
    setEditing(row.id);
    setForm({ kind: row.kind, name: row.name });
  };

  const close = () => {
    setEditing(null);
    setForm({ kind: 'asset', name: '' });
  };

  const closeDelete = () => {
    setDeleting(null);
    setTarget('');
  };

  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    const name = form.name.trim();
    if (name === '') {
      return;
    }
    if (editing === 'new') {
      create.mutate({ kind: form.kind, name }, { onSuccess: close });
      return;
    }
    if (editing === null) {
      return;
    }
    update.mutate({ id: editing, name }, { onSuccess: close });
  };

  /**
   * Delete in two steps: an unused row goes straight through, a row with items
   * opens the reassign panel so the target is chosen before the request instead
   * of after a refused one.
   */
  const requestDelete = (row: CategoryRow) => {
    remove.reset();
    if (row.usage > 0) {
      const firstTarget = categories.find((candidate) => candidate.kind === row.kind && candidate.id !== row.id);
      setDeleting(row);
      setTarget(firstTarget?.name ?? '');
      return;
    }
    if (window.confirm(t('set_category_delete_confirm', { name: row.name }))) {
      remove.mutate({ id: row.id, reassignTo: null });
    }
  };

  const submitDelete = (event: FormEvent) => {
    event.preventDefault();
    if (deleting === null) {
      return;
    }
    remove.mutate({ id: deleting.id, reassignTo: target === '' ? null : target }, { onSuccess: closeDelete });
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

  /** Siblings of a row that can receive its items. */
  const targetsOf = (row: CategoryRow) =>
    categories.filter((candidate) => candidate.kind === row.kind && candidate.id !== row.id);

  return (
    <section aria-labelledby={labelledBy} className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2">
        <p className="m-0 text-sm text-[var(--color-muted)]">{t('set_categories_intro')}</p>
        <Button className="ms-auto" variant="primary" onClick={openCreate}>
          {t('set_category_add')}
        </Button>
      </div>

      {editing === null ? null : (
        <form onSubmit={onSubmit} className="card flex flex-col gap-3 p-4">
          <h3 className="m-0 text-sm font-semibold">
            {editing === 'new' ? t('set_category_create') : t('set_category_edit')}
          </h3>
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="flex flex-col gap-1">
              <label htmlFor="category-kind" className="text-xs font-medium text-[var(--color-muted)]">
                {t('set_category_kind')}
              </label>
              <Select
                id="category-kind"
                value={form.kind}
                // The kind is part of the row identity (`UNIQUE(kind,name)`) and
                // owns the items the rename moves, so it is fixed afterwards.
                disabled={editing !== 'new'}
                onChange={(event) => setForm({ ...form, kind: event.target.value as CategoryKind })}
              >
                {kinds.map((kind) => (
                  <option key={kind} value={kind}>
                    {t(inventoryListKey(kind))}
                  </option>
                ))}
              </Select>
              {errorLine('kind')}
            </div>
            <div className="flex flex-col gap-1">
              <label htmlFor="category-name" className="text-xs font-medium text-[var(--color-muted)]">
                {t('set_category_name')}
              </label>
              <Input
                id="category-name"
                value={form.name}
                autoFocus
                onChange={(event) => setForm({ ...form, name: event.target.value })}
              />
              <p className="m-0 text-xs text-[var(--color-muted)]">{t('set_category_name_hint')}</p>
              {errorLine('name')}
            </div>
          </div>
          <div className="flex items-center gap-2">
            <Button type="submit" variant="primary" disabled={mutation.isPending || form.name.trim() === ''}>
              {t('save_changes')}
            </Button>
            <Button variant="ghost" onClick={close}>
              {t('cancel')}
            </Button>
            {mutation.isPending ? <Spinner /> : null}
          </div>
        </form>
      )}

      {deleting === null ? null : (
        <form onSubmit={submitDelete} className="card flex flex-col gap-3 p-4">
          <h3 className="m-0 text-sm font-semibold">{t('set_category_reassign_title', { name: deleting.name })}</h3>
          <p className="m-0 text-sm text-[var(--color-muted)]">{t('set_category_reassign_intro')}</p>
          <div className="flex flex-wrap items-end gap-3">
            <label
              htmlFor="category-move-target"
              className="flex min-w-56 flex-col gap-1 text-xs font-medium text-[var(--color-muted)]"
            >
              {t('set_category_reassign')}
              <Select id="category-move-target" value={target} onChange={(event) => setTarget(event.target.value)}>
                {targetsOf(deleting).map((candidate) => (
                  <option key={candidate.id} value={candidate.name}>
                    {candidate.name}
                  </option>
                ))}
              </Select>
            </label>
            <Button type="submit" variant="primary" disabled={remove.isPending || target === ''}>
              {t('set_delete')}
            </Button>
            <Button variant="ghost" onClick={closeDelete}>
              {t('cancel')}
            </Button>
            {remove.isPending ? <Spinner /> : null}
          </div>
          {remove.error instanceof PanelApiError ? (
            <p className="m-0 text-xs text-[var(--color-danger)]">{remove.error.message}</p>
          ) : null}
        </form>
      )}

      {kinds.map((kind) => {
        const rows = categories.filter((row) => row.kind === kind);
        return (
          <div key={kind} className="flex flex-col gap-2">
            <h3 className="m-0 text-sm font-semibold">{t(inventoryListKey(kind))}</h3>
            <div className="card overflow-x-auto p-0">
              <table className="w-full border-collapse">
                <thead>
                  <tr className="border-b border-[var(--color-border)]">
                    <th className={TH}>{t('set_category_name')}</th>
                    <th className={TH}>{t('set_company_usage_header')}</th>
                    <th className={TH}>{t('set_actions')}</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => {
                    // The last category of a kind is the one row the delete can
                    // never remove: every item of that tab must keep a valid
                    // value, so with no vocabulary left the field could not be
                    // filled in again.
                    const last = rows.length <= 1;
                    const hint = last ? 'set_category_last' : row.usage > 0 ? 'set_category_in_use' : null;
                    return (
                      <tr key={row.id} className="border-b border-[var(--color-border)] last:border-0">
                        <td className={TD}>{row.name}</td>
                        <td className={TD}>
                          <span className="text-xs text-[var(--color-muted)]">
                            {t('set_category_usage', { count: row.usage })}
                          </span>
                        </td>
                        <td className={TD}>
                          <span className="flex flex-wrap items-center gap-1">
                            <Button variant="ghost" onClick={() => openRename(row)}>
                              {t('set_edit')}
                            </Button>
                            <Button
                              variant="ghost"
                              disabled={last || remove.isPending}
                              title={hint === null ? undefined : t(hint)}
                              onClick={() => requestDelete(row)}
                            >
                              {t('set_delete')}
                            </Button>
                          </span>
                        </td>
                      </tr>
                    );
                  })}
                  {rows.length === 0 ? (
                    <tr>
                      <td className={TD} colSpan={3}>
                        {t('set_category_empty')}
                      </td>
                    </tr>
                  ) : null}
                </tbody>
              </table>
            </div>
          </div>
        );
      })}
    </section>
  );
}
