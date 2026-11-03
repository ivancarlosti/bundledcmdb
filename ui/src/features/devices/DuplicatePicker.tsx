import { X } from 'lucide-react';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button, Input, Spinner } from '@/components/ui';

import type { DeviceRow } from './api';
import { useDeviceSearch } from './useDevices';

/** How a row is named in the picker: serial number first, like the detail title. */
export function deviceLabel(row: Pick<DeviceRow, 'id' | 'sn' | 'hostname'>): string {
  if (row.sn !== null && row.sn !== '') {
    return row.hostname !== null && row.hostname !== '' ? `${row.sn} · ${row.hostname}` : row.sn;
  }
  return row.hostname !== null && row.hostname !== '' ? row.hostname : `#${row.id}`;
}

export type DuplicateTarget = { id: number; label: string };

export type DuplicatePickerProps = {
  /** Device being deleted: it can never be its own duplicate. */
  excludeId: number;
  value: DuplicateTarget | null;
  onChange: (value: DuplicateTarget | null) => void;
  /** Server error mapped to `duplicate_of` (`not_found`, `deleted`, …). */
  error?: string | null;
  /** Id of the search input, so the surrounding `<label>` can point at it. */
  inputId?: string;
};

/**
 * "Duplicate of" picker: type a serial number or hostname, pick the kept device.
 *
 * The API validates the reference again (`422 duplicate_of`), so this stays a
 * convenience: filtering happens server-side (`/devices?q=…`) because the table is
 * larger than anything the client should page through to fill a dropdown.
 */
export function DuplicatePicker({ excludeId, value, onChange, error, inputId }: DuplicatePickerProps) {
  const { t } = useTranslation();
  const listId = useId();
  const [term, setTerm] = useState('');
  const search = useDeviceSearch(term, excludeId);
  const results = search.data ?? [];

  if (value !== null) {
    return (
      <div className="flex items-center gap-2">
        <span className="inline-flex min-w-0 items-center gap-1 rounded-full bg-[var(--color-surface-3)] px-2 py-1 text-sm">
          <span className="truncate">{value.label}</span>
        </span>
        <Button variant="ghost" className="h-7 px-2" onClick={() => onChange(null)}>
          <X aria-hidden="true" className="size-4" />
          {t('clear')}
        </Button>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-1">
      <Input
        id={inputId}
        type="search"
        value={term}
        placeholder={t('ui_duplicate_of_placeholder')}
        aria-controls={listId}
        aria-invalid={error !== null && error !== undefined}
        onChange={(event) => setTerm(event.target.value)}
      />
      <div id={listId} aria-live="polite" className="flex flex-col gap-1">
        {search.isFetching ? (
          <span className="flex items-center gap-2 text-xs text-[var(--color-muted)]">
            <Spinner className="size-3" /> {t('ui_loading')}
          </span>
        ) : null}
        {term.trim().length >= 2 && !search.isFetching && results.length === 0 ? (
          <p className="m-0 text-xs text-[var(--color-muted)]">{t('ui_duplicate_of_empty', { q: term.trim() })}</p>
        ) : null}
        {results.length === 0 ? null : (
          <ul className="m-0 flex list-none flex-col gap-1 p-0">
            {results.map((row) => (
              <li key={row.id}>
                <Button
                  variant="ghost"
                  className="h-8 w-full justify-start px-2 text-sm"
                  onClick={() => onChange({ id: row.id, label: deviceLabel(row) })}
                >
                  {deviceLabel(row)}
                </Button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
