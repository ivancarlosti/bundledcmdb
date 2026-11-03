import { ChevronDown, ChevronUp, Columns3 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui';

import { moveColumn, toggleColumn } from './columnLayout';

export type ColumnPickerItem = { id: string; label: string };

export type ColumnPickerProps = {
  items: ColumnPickerItem[];
  order: string[];
  visible: string[];
  disabled?: boolean;
  onChange: (next: { order: string[]; visible: string[] }) => void;
};

/**
 * Column chooser.
 *
 * A native `<details>` panel, so it opens, closes and traps nothing without a
 * portal; every row is a checkbox with two real buttons (`Move up`/`Move down`),
 * which makes reordering keyboard-only. Pointer drag-and-drop is intentionally
 * not implemented yet — buttons are the accessible baseline.
 */
export function ColumnPicker({ items, order, visible, disabled = false, onChange }: ColumnPickerProps) {
  const { t } = useTranslation();
  const labels = new Map(items.map((item) => [item.id, item.label]));

  return (
    <details className="relative shrink-0">
      <summary className="inline-flex cursor-pointer list-none items-center gap-1.5 rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-1.5 text-sm font-medium hover:bg-[var(--color-surface-2)]">
        <Columns3 aria-hidden="true" className="size-4" />
        {t('ui_columns')}
      </summary>
      <div className="card absolute end-0 z-20 mt-1 w-80 p-3 shadow-lg">
        <p className="m-0 mb-2 text-xs text-[var(--color-muted)]">{t('ui_columns_hint')}</p>
        <ul className="m-0 flex list-none flex-col gap-0.5 p-0">
          {order.map((id, index) => {
            const label = labels.get(id) ?? id;
            const checked = visible.includes(id);
            return (
              <li key={id} className="flex items-center gap-1.5">
                <input
                  type="checkbox"
                  id={`column-toggle-${id}`}
                  className="size-4 accent-[var(--color-accent)]"
                  checked={checked}
                  disabled={disabled}
                  onChange={() => onChange({ order, visible: toggleColumn(visible, id) })}
                />
                <label htmlFor={`column-toggle-${id}`} className="min-w-0 flex-1 truncate text-sm">
                  {label}
                </label>
                <button
                  type="button"
                  aria-label={t('ui_move_up', { name: label })}
                  disabled={disabled || index === 0}
                  className="rounded-[var(--radius-sm)] p-0.5 hover:bg-[var(--color-surface-3)] disabled:opacity-30"
                  onClick={() => onChange({ order: moveColumn(order, id, -1), visible })}
                >
                  <ChevronUp aria-hidden="true" className="size-4" />
                </button>
                <button
                  type="button"
                  aria-label={t('ui_move_down', { name: label })}
                  disabled={disabled || index === order.length - 1}
                  className="rounded-[var(--radius-sm)] p-0.5 hover:bg-[var(--color-surface-3)] disabled:opacity-30"
                  onClick={() => onChange({ order: moveColumn(order, id, 1), visible })}
                >
                  <ChevronDown aria-hidden="true" className="size-4" />
                </button>
              </li>
            );
          })}
        </ul>
        <div className="mt-2 flex justify-end border-t border-[var(--color-border)] pt-2">
          <Button
            variant="ghost"
            disabled={disabled}
            onClick={() => onChange({ order: items.map((item) => item.id), visible: items.map((item) => item.id) })}
          >
            {t('ui_reset_columns')}
          </Button>
        </div>
      </div>
    </details>
  );
}
