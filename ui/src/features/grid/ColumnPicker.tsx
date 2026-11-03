import {
  closestCenter,
  DndContext,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
} from '@dnd-kit/core';
import { restrictToParentElement, restrictToVerticalAxis } from '@dnd-kit/modifiers';
import {
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { ChevronDown, ChevronUp, Columns3, GripVertical } from 'lucide-react';
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

/** One row of the picker: a grip handle, the checkbox and the keyboard buttons. */
function SortableRow({
  id,
  label,
  checked,
  disabled,
  isFirst,
  isLast,
  onToggle,
  onMove,
}: {
  id: string;
  label: string;
  checked: boolean;
  disabled: boolean;
  isFirst: boolean;
  isLast: boolean;
  onToggle: () => void;
  onMove: (direction: -1 | 1) => void;
}) {
  const { t } = useTranslation();
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id, disabled });

  return (
    <li
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={`flex items-center gap-1.5 ${isDragging ? 'opacity-60' : ''}`}
      data-testid={`column-row-${id}`}
    >
      <button
        type="button"
        aria-label={t('ui_drag_column', { name: label })}
        disabled={disabled}
        className="cursor-grab touch-none rounded-[var(--radius-sm)] p-0.5 text-[var(--color-muted)] hover:bg-[var(--color-surface-3)] disabled:cursor-not-allowed disabled:opacity-30"
        {...attributes}
        {...listeners}
      >
        <GripVertical aria-hidden="true" className="size-4" />
      </button>
      <input
        type="checkbox"
        id={`column-toggle-${id}`}
        className="size-4 accent-[var(--color-accent)]"
        checked={checked}
        disabled={disabled}
        onChange={onToggle}
      />
      <label htmlFor={`column-toggle-${id}`} className="min-w-0 flex-1 truncate text-sm">
        {label}
      </label>
      <button
        type="button"
        aria-label={t('ui_move_up', { name: label })}
        disabled={disabled || isFirst}
        className="rounded-[var(--radius-sm)] p-0.5 hover:bg-[var(--color-surface-3)] disabled:opacity-30"
        onClick={() => onMove(-1)}
      >
        <ChevronUp aria-hidden="true" className="size-4" />
      </button>
      <button
        type="button"
        aria-label={t('ui_move_down', { name: label })}
        disabled={disabled || isLast}
        className="rounded-[var(--radius-sm)] p-0.5 hover:bg-[var(--color-surface-3)] disabled:opacity-30"
        onClick={() => onMove(1)}
      >
        <ChevronDown aria-hidden="true" className="size-4" />
      </button>
    </li>
  );
}

/**
 * Column chooser.
 *
 * A native `<details>` panel, so it opens, closes and traps nothing without a
 * portal. Reordering works two ways: a pointer drag on the grip handle
 * (`@dnd-kit`, vertical axis, restricted to the list) and the up/down buttons.
 * The handle is a real button, so `KeyboardSensor` (space + arrows) moves a row
 * without a pointer and a screen reader hears the move instead of being
 * surprised by a silent reorder.
 */
export function ColumnPicker({ items, order, visible, disabled = false, onChange }: ColumnPickerProps) {
  const { t } = useTranslation();
  const labels = new Map(items.map((item) => [item.id, item.label]));

  const sensors = useSensors(
    // A small activation distance keeps a plain click on the handle from starting
    // a drag (and from stealing focus).
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  const onDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    if (over === null || active.id === over.id) {
      return;
    }
    const from = order.indexOf(String(active.id));
    const to = order.indexOf(String(over.id));
    if (from === -1 || to === -1) {
      return;
    }
    onChange({ order: moveColumn(order, String(active.id), to - from), visible });
  };

  return (
    <details className="relative shrink-0">
      <summary className="inline-flex cursor-pointer list-none items-center gap-1.5 rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-1.5 text-sm font-medium hover:bg-[var(--color-surface-2)]">
        <Columns3 aria-hidden="true" className="size-4" />
        {t('ui_columns')}
      </summary>
      <div className="card absolute end-0 z-20 mt-1 w-80 p-3 shadow-lg">
        <p className="m-0 mb-2 text-xs text-[var(--color-muted)]">{t('ui_columns_hint')}</p>
        <DndContext
          sensors={sensors}
          collisionDetection={closestCenter}
          modifiers={[restrictToVerticalAxis, restrictToParentElement]}
          onDragEnd={onDragEnd}
        >
          <SortableContext items={order} strategy={verticalListSortingStrategy}>
            <ul className="m-0 flex list-none flex-col gap-0.5 p-0">
              {order.map((id, index) => {
                const label = labels.get(id) ?? id;
                return (
                  <SortableRow
                    key={id}
                    id={id}
                    label={label}
                    checked={visible.includes(id)}
                    disabled={disabled}
                    isFirst={index === 0}
                    isLast={index === order.length - 1}
                    onToggle={() => onChange({ order, visible: toggleColumn(visible, id) })}
                    onMove={(direction) => onChange({ order: moveColumn(order, id, direction), visible })}
                  />
                );
              })}
            </ul>
          </SortableContext>
        </DndContext>
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
