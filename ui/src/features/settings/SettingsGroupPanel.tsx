/**
 * The settings form for one tab.
 *
 * The tab gets a list of server-side groups (`general` + `assets` share a tab,
 * `storage` has its own) and renders one control per setting — the control is
 * chosen from the `type` the server published, so adding a setting never needs
 * a change here.
 */

import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';

import { PanelApiError } from '@/api/client';
import { Alert, Button, Checkbox, Input, Select, Spinner, Textarea } from '@/components/ui';

import type { SettingEntry, SettingGroup, SettingsPayload } from './api';
import { buildSettingsPatch, settingsErrorKey, settingsFormFrom, type SettingsForm } from './settingsForm';
import { useSaveSettings, useSettings } from './useSettings';

const BADGE =
  'rounded-[var(--radius-md)] bg-[var(--color-surface-2)] px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-[var(--color-muted)]';

type ControlProps = {
  entry: SettingEntry;
  payload: SettingsPayload;
  value: string;
  invalid: boolean;
  onChange: (value: string) => void;
};

/** One input, picked from `entry.type`. */
function SettingControl({ entry, payload, value, invalid, onChange }: ControlProps) {
  const { t } = useTranslation();
  const id = `set_${entry.key.replace(/\./g, '_')}`;
  const invalidClass = invalid ? 'border-[var(--color-danger)]' : undefined;

  switch (entry.type) {
    case 'bool':
      return (
        <Checkbox
          id={id}
          label={t('yes')}
          checked={value === '1'}
          onChange={(event) => onChange(event.target.checked ? '1' : '0')}
        />
      );

    case 'int':
      return (
        <Input
          id={id}
          type="number"
          className={invalidClass}
          value={value}
          min={entry.min ?? undefined}
          max={entry.max ?? undefined}
          onChange={(event) => onChange(event.target.value)}
        />
      );

    case 'timezone':
      return (
        <Select id={id} className={invalidClass} value={value} onChange={(event) => onChange(event.target.value)}>
          <option value="">— UTC —</option>
          {payload.timezones.map((zone) => (
            <option key={zone} value={zone}>
              {zone}
            </option>
          ))}
        </Select>
      );

    case 'locale':
      return (
        <Select id={id} className={invalidClass} value={value} onChange={(event) => onChange(event.target.value)}>
          <option value="">— {t('set_follow_browser')} —</option>
          {payload.locales.map((locale) => (
            <option key={locale.code} value={locale.code}>
              {locale.native}
            </option>
          ))}
        </Select>
      );

    case 'select':
      return (
        <Select id={id} className={invalidClass} value={value} onChange={(event) => onChange(event.target.value)}>
          {entry.options.map((option) => {
            // `system`/`light`/`dark` are translated (`set_theme_*`); anything
            // else falls back to the label the definition carries.
            const themeKey = `set_theme_${option.value}`;
            const translated = t(themeKey);
            return (
              <option key={option.value} value={option.value}>
                {translated === themeKey ? option.label : translated}
              </option>
            );
          })}
        </Select>
      );

    case 'source':
      return (
        <Select id={id} className={invalidClass} value={value} onChange={(event) => onChange(event.target.value)}>
          {payload.sources.map((source) => (
            <option key={source.slug} value={source.slug}>
              {source.label}
            </option>
          ))}
        </Select>
      );

    case 'textarea':
      return (
        <Textarea
          id={id}
          className={invalidClass}
          rows={3}
          placeholder={t('set_allowlist_hint')}
          value={value}
          onChange={(event) => onChange(event.target.value)}
        />
      );

    case 'secret':
      return (
        <Input
          id={id}
          type="password"
          autoComplete="new-password"
          className={invalidClass}
          value={value}
          placeholder={entry.configured === true ? t('set_secret_keep') : t('set_secret_empty')}
          onChange={(event) => onChange(event.target.value)}
        />
      );

    default:
      return (
        <Input id={id} className={invalidClass} value={value} onChange={(event) => onChange(event.target.value)} />
      );
  }
}

function flatten(groups: SettingGroup[], wanted: string[]): SettingEntry[] {
  return groups.filter((group) => wanted.includes(group.id)).flatMap((group) => group.settings);
}

type Props = {
  /** Group ids this tab renders, in display order. */
  groups: string[];
  intro: string;
  /** Id of the `<h1>` the tab belongs to (`aria-labelledby`). */
  labelledBy: string;
};

export function SettingsGroupPanel({ groups: wanted, intro, labelledBy }: Props) {
  const { t } = useTranslation();
  const query = useSettings();
  const save = useSaveSettings();

  const sections = useMemo(
    () => (query.data?.groups ?? []).filter((group) => wanted.includes(group.id)),
    [query.data, wanted],
  );
  const entries = useMemo(() => flatten(query.data?.groups ?? [], wanted), [query.data, wanted]);

  const [form, setForm] = useState<SettingsForm>(() => settingsFormFrom(entries));

  // Re-seed after every payload change: a save returns the stored values and the
  // new `configured` flags, which is what the "leave blank to keep" hint reads.
  useEffect(() => {
    setForm(settingsFormFrom(entries));
  }, [entries]);

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

  const payload = query.data;
  const fieldErrors =
    save.error instanceof PanelApiError && save.error.isValidation ? save.error.errors : {};

  const setValue = (key: string, value: string) => {
    setForm((current) => ({ ...current, values: { ...current.values, [key]: value } }));
  };

  const toggleClear = (key: string, cleared: boolean) => {
    setForm((current) => ({ ...current, clears: { ...current.clears, [key]: cleared } }));
  };

  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    save.mutate(buildSettingsPatch(entries, form));
  };

  return (
    <form onSubmit={onSubmit} aria-labelledby={labelledBy} className="flex flex-col gap-4">
      <p className="m-0 text-sm text-[var(--color-muted)]">{intro}</p>

      {sections.map((section) => (
        <section key={section.id} className="card flex flex-col gap-3 p-4">
          <h3 className="m-0 text-sm font-semibold">{t(section.label_key)}</h3>
          {section.settings.map((entry) => {
            const id = `set_${entry.key.replace(/\./g, '_')}`;
            const code = fieldErrors[entry.key];
            const errorKey = code === undefined ? null : settingsErrorKey(code);
            return (
              <div key={entry.key} className="flex flex-col gap-1">
                {entry.type === 'bool' ? null : (
                  <label htmlFor={id} className="text-xs font-medium text-[var(--color-muted)]">
                    {t(entry.label_key)}
                  </label>
                )}
                <SettingControl
                  entry={entry}
                  payload={payload}
                  value={form.values[entry.key] ?? ''}
                  invalid={code !== undefined}
                  onChange={(value) => setValue(entry.key, value)}
                />
                <div className="flex flex-wrap items-center gap-2">
                  <span className={BADGE}>{t(`set_source_${entry.source}`)}</span>
                  {entry.secret && entry.configured === true ? (
                    <Checkbox
                      label={t('set_secret_clear')}
                      checked={form.clears[entry.key] === true}
                      onChange={(event) => toggleClear(entry.key, event.target.checked)}
                    />
                  ) : null}
                </div>
                {code === undefined ? null : (
                  <p className="m-0 text-xs text-[var(--color-danger)]">
                    {errorKey === null ? code : t(errorKey)}
                  </p>
                )}
              </div>
            );
          })}
        </section>
      ))}

      <div className="flex items-center gap-2">
        <Button type="submit" variant="primary" disabled={save.isPending}>
          {t('save_changes')}
        </Button>
        {save.isPending ? <Spinner /> : null}
      </div>
    </form>
  );
}
