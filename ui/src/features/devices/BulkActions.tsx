import { Trash2, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button, Select } from '@/components/ui';

export type BulkActionsProps = {
  /** How many rows are selected (the toolbar is only rendered when > 0). */
  count: number;
  /** Translatable status options (`/meta` → `statuses`). */
  statuses: { value: string; label: string }[];
  /** Translatable deletion reasons (`/meta` → `deleteReasons`). */
  deleteReasons: { value: string; label: string }[];
  /** Delete/restore are admin-only, so they are hidden (not disabled) for managers. */
  canAdmin: boolean;
  pending: boolean;
  onStatus: (status: string) => void;
  onByod: (value: boolean) => void;
  /** `null` when the optional reason was left empty. */
  onDelete: (reason: string | null) => void;
  onRestore: () => void;
  onClear: () => void;
};

/**
 * Selection toolbar for the device grid.
 *
 * Every action states its own target ("Set status", "Set BYOD", "Delete",
 * "Restore") instead of a single ambiguous "Apply", the way the legacy bulk bar
 * did — a wrong click here edits 25 rows. The count is announced through
 * `role="status"`, so a screen-reader user hears the selection change too.
 *
 * The reason next to Delete is optional on purpose: a batch is often mixed, and
 * the API stores `NULL` for "no reason given" instead of refusing the action.
 */
export function BulkActions({
  count,
  statuses,
  deleteReasons,
  canAdmin,
  pending,
  onStatus,
  onByod,
  onDelete,
  onRestore,
  onClear,
}: BulkActionsProps) {
  const { t } = useTranslation();
  const [status, setStatus] = useState(statuses[0]?.value ?? '');
  const [byod, setByod] = useState('true');
  const [reason, setReason] = useState('');

  return (
    <div
      className="card flex flex-wrap items-center gap-2 p-3"
      role="region"
      aria-label={t('ui_bulk_actions')}
      data-testid="bulk-actions"
    >
      <p className="m-0 text-sm font-medium" role="status" aria-live="polite">
        {t('ui_selected_count', { total: count })}
      </p>

      <Select
        value={status}
        aria-label={t('ui_bulk_status')}
        className="w-auto"
        onChange={(event) => setStatus(event.target.value)}
      >
        {statuses.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </Select>
      <Button variant="secondary" disabled={pending || status === ''} onClick={() => onStatus(status)}>
        {t('ui_bulk_apply')}
      </Button>

      <Select
        value={byod}
        aria-label={t('ui_bulk_byod')}
        className="w-auto"
        onChange={(event) => setByod(event.target.value)}
      >
        <option value="true">{t('yes')}</option>
        <option value="false">{t('no')}</option>
      </Select>
      <Button variant="secondary" disabled={pending} onClick={() => onByod(byod === 'true')}>
        {t('ui_bulk_apply')}
      </Button>

      {canAdmin ? (
        <>
          <Button variant="secondary" disabled={pending} onClick={onRestore}>
            <Undo2 aria-hidden="true" className="size-4" />
            {t('ui_restore')}
          </Button>
          <Select
            value={reason}
            aria-label={t('ui_delete_reason')}
            className="w-auto"
            data-testid="bulk-delete-reason"
            onChange={(event) => setReason(event.target.value)}
          >
            <option value="">{t('ui_delete_reason_none')}</option>
            {deleteReasons.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
          <Button variant="danger" disabled={pending} onClick={() => onDelete(reason === '' ? null : reason)}>
            <Trash2 aria-hidden="true" className="size-4" />
            {t('delete')}
          </Button>
        </>
      ) : null}

      <Button variant="ghost" className="ms-auto" disabled={pending} onClick={onClear}>
        {t('ui_bulk_clear')}
      </Button>
    </div>
  );
}
