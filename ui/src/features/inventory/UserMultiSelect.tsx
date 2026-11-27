/**
 * Assignment picker: any number of people, chosen from the company roster.
 *
 * A plain `<select>` that appends a chip instead of a combobox: the roster is a few
 * hundred rows at most, the list needs no search, and a native select keeps the
 * keyboard/screen-reader behaviour for free.
 *
 * `disabled` is what "Common usage" drives: a shared item is assigned to nobody, so
 * the caller clears the value and turns the control off.
 */

import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';

import { Select, Spinner } from '@/components/ui';

import { useUserDirectory } from './useInventory';

export function UserMultiSelect({
  company,
  value,
  onChange,
  disabled = false,
}: {
  company: string;
  value: string[];
  onChange: (next: string[]) => void;
  disabled?: boolean;
}) {
  const { t } = useTranslation();
  const directory = useUserDirectory(company);

  const options = useMemo(() => {
    const chosen = new Set(value);
    return (directory.data ?? [])
      .map((user) => user.email)
      .filter((email) => !chosen.has(email))
      .sort((a, b) => a.localeCompare(b));
  }, [directory.data, value]);

  const remove = (email: string) => onChange(value.filter((entry) => entry !== email));

  return (
    <div className="flex flex-col gap-2">
      {value.length === 0 ? (
        <p className="m-0 text-xs text-[var(--color-muted)]">{t('ui_assign_no_users')}</p>
      ) : (
        <ul className="m-0 flex list-none flex-wrap gap-1.5 p-0">
          {value.map((email) => (
            <li
              key={email}
              className="inline-flex items-center gap-1 rounded-full bg-[var(--color-surface-3)] px-2 py-0.5 text-xs"
            >
              <span className="max-w-[24ch] truncate" title={email}>
                {email}
              </span>
              <button
                type="button"
                className="rounded-full px-1 text-[var(--color-muted)] hover:text-[var(--color-danger)] disabled:opacity-40"
                aria-label={`${t('ui_remove_file')}: ${email}`}
                disabled={disabled}
                onClick={() => remove(email)}
              >
                ×
              </button>
            </li>
          ))}
        </ul>
      )}

      <div className="flex items-center gap-2">
        <Select
          aria-label={t('ui_assigned_users')}
          className="max-w-[22rem]"
          value=""
          disabled={disabled || directory.isPending}
          onChange={(event) => {
            const email = event.target.value;
            if (email !== '') {
              onChange([...value, email]);
            }
          }}
        >
          <option value="">{t('ui_assign_add')}</option>
          {options.map((email) => (
            <option key={email} value={email}>
              {email}
            </option>
          ))}
        </Select>
        {directory.isFetching ? <Spinner className="size-4" /> : null}
      </div>
    </div>
  );
}
