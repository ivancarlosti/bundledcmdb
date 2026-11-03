import { Bookmark, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button, Input } from '@/components/ui';

import type { SavedView, SavedViewState } from './api';
import { useDeleteView, useSaveView, useViews } from './useViews';

/**
 * Saved views menu.
 *
 * Same `<details>` pattern as the column picker: no portal, keyboard-operable and
 * it closes on a second click. Applying a view hands the stored state back to the
 * grid, which puts it in the URL like any other filter change.
 */
export function SavedViewsMenu({
  currentState,
  onApply,
}: {
  currentState: SavedViewState;
  onApply: (state: SavedViewState) => void;
}) {
  const { t } = useTranslation();
  const views = useViews();
  const save = useSaveView();
  const remove = useDeleteView();
  const [name, setName] = useState('');

  const onSave = () => {
    const trimmed = name.trim();
    if (trimmed === '') {
      return;
    }
    save.mutate(
      { name: trimmed, state: currentState },
      {
        onSuccess: () => {
          setName('');
        },
      },
    );
  };

  const rows: SavedView[] = views.data ?? [];

  return (
    <details className="relative shrink-0">
      <summary
        data-testid="views-menu"
        className="inline-flex cursor-pointer list-none items-center gap-1.5 rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-1.5 text-sm font-medium hover:bg-[var(--color-surface-2)]"
      >
        <Bookmark aria-hidden="true" className="size-4" />
        {t('ui_views')}
        {rows.length > 0 ? <span className="text-xs text-[var(--color-muted)]">{rows.length}</span> : null}
      </summary>
      <div className="card absolute end-0 z-20 mt-1 w-80 p-3 shadow-lg">
        {rows.length === 0 ? (
          <p className="m-0 mb-2 text-xs text-[var(--color-muted)]">{t('ui_views_empty')}</p>
        ) : (
          <ul className="m-0 mb-2 flex list-none flex-col gap-0.5 p-0">
            {rows.map((view) => (
              <li key={view.id} className="flex items-center gap-1.5">
                <button
                  type="button"
                  className="min-w-0 flex-1 truncate rounded-[var(--radius-sm)] px-1.5 py-1 text-start text-sm hover:bg-[var(--color-surface-2)]"
                  onClick={() => onApply(view.state)}
                >
                  {view.name}
                </button>
                <button
                  type="button"
                  aria-label={t('ui_view_delete', { name: view.name })}
                  disabled={remove.isPending}
                  className="rounded-[var(--radius-sm)] p-0.5 text-[var(--color-danger)] hover:bg-[var(--color-surface-3)] disabled:opacity-30"
                  onClick={() => remove.mutate(view.id)}
                >
                  <Trash2 aria-hidden="true" className="size-4" />
                </button>
              </li>
            ))}
          </ul>
        )}

        <div className="flex items-center gap-1.5 border-t border-[var(--color-border)] pt-2">
          <Input
            aria-label={t('ui_view_name')}
            placeholder={t('ui_view_name')}
            value={name}
            maxLength={64}
            onChange={(event) => setName(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                event.preventDefault();
                onSave();
              }
            }}
          />
          <Button type="button" variant="secondary" disabled={save.isPending || name.trim() === ''} onClick={onSave}>
            {t('ui_view_save')}
          </Button>
        </div>
      </div>
    </details>
  );
}
