import { useTranslation } from 'react-i18next';

import { Field, Select } from '@/components/ui';

import type { MetaPayload } from './api';
import { DuplicatePicker, type DuplicateTarget } from './DuplicatePicker';

/** What the delete action collects: a reason value and, for duplicates, the kept row. */
export type DeleteReasonState = {
  reason: string;
  duplicateOf: DuplicateTarget | null;
};

export const EMPTY_DELETE_REASON: DeleteReasonState = { reason: '', duplicateOf: null };

/** Value the API uses for "this row duplicates another one". */
export const DUPLICATED_REASON = 'duplicated';

/**
 * Translation key of a stored `deleted_reason`, from `GET /meta` → `deleteReasons`.
 *
 * The trash alert reads a value that was written months ago, so the label has to
 * come from the catalogue rather than from a hardcoded map here; `null` means the
 * row was deleted before 0014 and has no reason at all.
 */
export function deleteReasonLabelKey(meta: MetaPayload | undefined, reason: string | null): string | null {
  if (reason === null || reason === '') {
    return null;
  }
  return meta?.deleteReasons.find((option) => option.value === reason)?.label_key ?? null;
}

export type DeleteReasonFieldsProps = {
  /** `/meta` → `deleteReasons`, already translated by the caller. */
  reasons: { value: string; label: string }[];
  value: DeleteReasonState;
  onChange: (next: DeleteReasonState) => void;
  /**
   * Id of the device being deleted. When set, the "duplicated" reason also asks
   * which asset was kept; bulk delete leaves it out (a list has no single one).
   */
  deviceId?: number;
  /** A single delete requires a reason; the bulk toolbar treats it as optional. */
  required?: boolean;
  /** Message shown under the select (`ui_delete_reason_required`, API errors). */
  error?: string | null;
  /** The API's `duplicate_of` field, when the reference was refused. */
  duplicateError?: string | null;
  idPrefix?: string;
};

/**
 * Reason selector shared by the single-device delete and the bulk toolbar.
 *
 * Deleting a device is a decision somebody will have to explain later — "the
 * trash keeps it" only answers where it went, not why — so the reason is a first
 * class field here and a column in the API (`assets.deleted_reason`).
 */
export function DeleteReasonFields({
  reasons,
  value,
  onChange,
  deviceId,
  required = false,
  error,
  duplicateError,
  idPrefix = 'delete',
}: DeleteReasonFieldsProps) {
  const { t } = useTranslation();
  const reasonId = `${idPrefix}-reason`;
  const duplicateId = `${idPrefix}-duplicate`;

  const onReasonChange = (reason: string) => {
    // Keeping the picker's answer after switching to "created by mistake" would
    // send a duplicate reference the API rejects (`reason_not_duplicated`).
    onChange({ reason, duplicateOf: reason === DUPLICATED_REASON ? value.duplicateOf : null });
  };

  return (
    <div className="flex flex-col gap-2">
      <Field label={t('ui_delete_reason')} htmlFor={reasonId}>
        <Select
          id={reasonId}
          value={value.reason}
          aria-invalid={error !== null && error !== undefined && error !== ''}
          onChange={(event) => onReasonChange(event.target.value)}
        >
          <option value="">{required ? t('ui_delete_reason_choose') : t('ui_delete_reason_none')}</option>
          {reasons.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </Select>
      </Field>

      {error !== null && error !== undefined && error !== '' ? (
        <p role="alert" className="m-0 text-xs text-[var(--color-danger)]">
          {error}
        </p>
      ) : null}

      {deviceId !== undefined && value.reason === DUPLICATED_REASON ? (
        <Field label={t('ui_duplicate_of')} htmlFor={duplicateId}>
          <DuplicatePicker
            inputId={duplicateId}
            excludeId={deviceId}
            value={value.duplicateOf}
            onChange={(duplicateOf) => onChange({ ...value, duplicateOf })}
            error={duplicateError}
          />
          <p className="m-0 text-xs text-[var(--color-muted)]">{t('ui_duplicate_of_hint')}</p>
          {duplicateError !== null && duplicateError !== undefined && duplicateError !== '' ? (
            <p role="alert" className="m-0 text-xs text-[var(--color-danger)]">
              {duplicateError}
            </p>
          ) : null}
        </Field>
      ) : null}
    </div>
  );
}
