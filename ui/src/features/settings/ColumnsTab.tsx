/**
 * Settings → Columns — the installation-wide device-grid layout.
 *
 * The list is the full catalogue the server publishes (`/settings/columns`),
 * already ordered; `enabled` is what the grid renders and `locked` makes that
 * decision final for every user (the per-user layout in `user_preferences` is
 * then ignored for order *and* visibility).
 *
 * Ordering uses the same up/down buttons the grid's column picker offers, so the
 * screen stays keyboard and screen-reader friendly without a drag implementation
 * of its own.
 */

import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ChevronDown, ChevronUp } from 'lucide-react';

import { Alert, Button, Checkbox, Spinner } from '@/components/ui';
import { moveColumn } from '@/features/grid/columnLayout';

import { useColumnMutations, useSettingsColumns } from './useSettings';

const ROW = 'flex items-center gap-2 border-b border-[var(--color-border)] py-1.5 last:border-b-0';

function sameList(a: string[], b: string[]): boolean {
  return a.length === b.length && a.every((id, index) => id === b[index]);
}

export function ColumnsTab({ labelledBy }: { labelledBy: string }) {
  const { t } = useTranslation();
  const query = useSettingsColumns();
  const { save, reset } = useColumnMutations();

  const [order, setOrder] = useState<string[] | null>(null);
  const [enabled, setEnabled] = useState<string[]>([]);
  const [locked, setLocked] = useState(false);

  useEffect(() => {
    if (query.data !== undefined) {
      setOrder(query.data.layout.order);
      setEnabled(query.data.layout.enabled);
      setLocked(query.data.layout.locked);
    }
  }, [query.data]);

  const entries = useMemo(() => {
    const map = new Map((query.data?.columns ?? []).map((column) => [column.id, column]));
    return (order ?? []).filter((id) => map.has(id)).map((id) => map.get(id)!);
  }, [order, query.data?.columns]);

  if (query.isPending || (query.data !== undefined && order === null)) {
    return (
      <div className="flex items-center gap-2 text-sm text-[var(--color-muted)]">
        <Spinner /> {t('ui_loading')}
      </div>
    );
  }

  if (query.isError || query.data === undefined || order === null) {
    return (
      <Alert
        title={t('ui_api_unavailable')}
        action={<Button onClick={() => void query.refetch()}>{t('ui_retry')}</Button>}
      >
        {query.error instanceof Error ? query.error.message : null}
      </Alert>
    );
  }

  const stored = query.data.layout;
  const dirty = !sameList(stored.order, order) || !sameList(stored.enabled, enabled) || stored.locked !== locked;
  const busy = save.isPending || reset.isPending;

  const toggle = (id: string) => {
    setEnabled((current) => {
      if (current.includes(id)) {
        // The grid cannot render zero columns, so the last one stays on.
        return current.length <= 1 ? current : current.filter((item) => item !== id);
      }
      return [...current, id];
    });
  };

  return (
    <div className="flex flex-col gap-3">
      <p className="m-0 text-sm text-[var(--color-muted)]">{t('set_columns_intro')}</p>

      <div className="card flex flex-col gap-2 p-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="m-0 text-sm font-medium">{t('set_columns_lock')}</p>
          <Checkbox
            checked={locked}
            disabled={busy}
            label={locked ? t('set_columns_locked_on') : t('set_columns_locked_off')}
            onChange={(event) => setLocked(event.target.checked)}
          />
        </div>
        <p className="m-0 text-xs text-[var(--color-muted)]">{t('set_columns_lock_hint')}</p>
      </div>

      <p className="m-0 text-xs text-[var(--color-muted)]">{t('set_columns_order_hint')}</p>

      <ul className="card m-0 flex list-none flex-col p-3" aria-labelledby={labelledBy} data-testid="columns-list">
        {entries.map((entry, index) => {
          const label = entry.label_key === null ? entry.label : t(entry.label_key, { defaultValue: entry.label });
          const checked = enabled.includes(entry.id);
          return (
            <li key={entry.id} className={ROW} data-testid={`settings-column-${entry.id}`}>
              <Checkbox
                checked={checked}
                disabled={busy || (checked && enabled.length <= 1)}
                label={label}
                onChange={() => toggle(entry.id)}
              />
              {entry.group === 'custom' ? (
                <span className="rounded-full bg-[var(--color-surface-3)] px-2 py-0.5 text-[10px] uppercase tracking-wide text-[var(--color-muted)]">
                  {t('set_columns_custom_badge')}
                </span>
              ) : null}
              <span className="ms-auto flex items-center gap-1">
                <button
                  type="button"
                  aria-label={t('set_columns_move_up', { name: label })}
                  disabled={busy || index === 0}
                  className="rounded-[var(--radius-sm)] p-0.5 hover:bg-[var(--color-surface-3)] disabled:opacity-30"
                  onClick={() =>
                    setOrder((current) => (current === null ? current : moveColumn(current, entry.id, -1)))
                  }
                >
                  <ChevronUp aria-hidden="true" className="size-4" />
                </button>
                <button
                  type="button"
                  aria-label={t('set_columns_move_down', { name: label })}
                  disabled={busy || index === entries.length - 1}
                  className="rounded-[var(--radius-sm)] p-0.5 hover:bg-[var(--color-surface-3)] disabled:opacity-30"
                  onClick={() => setOrder((current) => (current === null ? current : moveColumn(current, entry.id, 1)))}
                >
                  <ChevronDown aria-hidden="true" className="size-4" />
                </button>
              </span>
            </li>
          );
        })}
      </ul>

      <div className="flex flex-wrap items-center gap-2">
        <Button variant="primary" disabled={busy || !dirty} onClick={() => save.mutate({ order, enabled, locked })}>
          {t('save_changes')}
        </Button>
        <Button variant="ghost" disabled={busy} onClick={() => reset.mutate()}>
          {t('set_columns_reset')}
        </Button>
      </div>
    </div>
  );
}
