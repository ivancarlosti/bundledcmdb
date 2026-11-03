import { Link, useNavigate, useParams } from '@tanstack/react-router';
import { useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';

import { PanelApiError } from '@/api/client';
import { Alert, Button, Input, Select, Spinner, Textarea } from '@/components/ui';
import { useCurrentSession } from '@/features/auth/SessionContext';
import { zodResolver } from '@/lib/zodResolver';

import type { DeviceDetail, MetaColumn, MetaField, MetaPayload } from './api';
import { buildDevicePayload, formFields, formKeys, initialFormValues, type FormValue } from './deviceForm';
import { buildDeviceSchema, formTabs, requireSn, type DeviceFormModel } from './deviceSchema';
import { useCreateDevice, useUpdateDevice } from './useDeviceMutations';
import { useDevice, useMeta } from './useDevices';

/** Validation code → translation key (unknown codes are shown verbatim). */
const ERROR_KEYS: Record<string, string> = {
  required: 'set_err_required',
  too_long: 'set_err_too_long',
  invalid_option: 'set_err_invalid_option',
  invalid_number: 'set_err_invalid_number',
  out_of_range: 'set_err_out_of_range',
  duplicate: 'set_err_duplicate',
  forbidden: 'ui_err_forbidden',
  unknown_field: 'ui_err_unknown',
  unknown_source: 'ui_err_unknown',
  immutable: 'ui_err_forbidden',
  invalid_email: 'ui_err_invalid_value',
  invalid_date: 'ui_err_invalid_value',
  invalid_datetime: 'ui_err_invalid_value',
  invalid_object: 'ui_err_invalid_value',
};

type ControlProps = {
  id: string;
  value: FormValue;
  column?: MetaColumn;
  field?: MetaField;
  meta: MetaPayload;
  invalid: boolean;
  onChange: (value: FormValue) => void;
};

/** One form control, chosen from the column/field type published by `/meta`. */
function DeviceControl({ id, value, column, field, meta, invalid, onChange }: ControlProps) {
  const { t } = useTranslation();
  const type = field?.type ?? column?.type ?? 'text';
  const invalidClass = invalid ? 'border-[var(--color-danger)]' : undefined;

  if (type === 'bool') {
    return (
      <Select
        id={id}
        className={invalidClass}
        value={value === true ? '1' : '0'}
        onChange={(event) => onChange(event.target.value === '1')}
      >
        <option value="1">{t('yes')}</option>
        <option value="0">{t('no')}</option>
      </Select>
    );
  }

  if (field !== undefined && field.options.length > 0) {
    return (
      <Select id={id} className={invalidClass} value={String(value)} onChange={(event) => onChange(event.target.value)}>
        <option value="">—</option>
        {field.options.map((option) => (
          <option key={option} value={option}>
            {option}
          </option>
        ))}
      </Select>
    );
  }

  if (column?.key === 'Status') {
    return (
      <Select id={id} className={invalidClass} value={String(value)} onChange={(event) => onChange(event.target.value)}>
        <option value="">—</option>
        {meta.statuses.map((status) => (
          <option key={status.value} value={status.value}>
            {t(status.label_key)}
          </option>
        ))}
      </Select>
    );
  }

  if (column?.key === 'source') {
    return (
      <Select id={id} className={invalidClass} value={String(value)} onChange={(event) => onChange(event.target.value)}>
        <option value="">—</option>
        {meta.sources.map((source) => (
          <option key={source.slug} value={source.slug}>
            {source.label}
          </option>
        ))}
      </Select>
    );
  }

  if (type === 'textarea' || column?.key === 'Notes') {
    return (
      <Textarea
        id={id}
        className={invalidClass}
        value={String(value)}
        onChange={(event) => onChange(event.target.value)}
      />
    );
  }

  const inputType =
    type === 'date'
      ? 'date'
      : type === 'datetime'
        ? 'datetime-local'
        : type === 'email'
          ? 'email'
          : type === 'url'
            ? 'url'
            : ['int', 'integer', 'decimal', 'number'].includes(type)
              ? 'number'
              : 'text';

  return (
    <Input
      id={id}
      className={invalidClass}
      type={inputType}
      value={String(value)}
      onChange={(event) => onChange(event.target.value)}
      aria-invalid={invalid || undefined}
    />
  );
}

type FormProps = {
  device: DeviceDetail | null;
  meta: MetaPayload;
};

/**
 * Create/edit form.
 *
 * Fields come from `/meta.writeColumns`, so the role matrix lives on the server:
 * a manager's form simply has no `cypher_id` input, and the API rejects the key
 * even if someone crafts the request by hand. Editing sends the row's
 * `updated_at` as `expected_updated_at`, which turns a concurrent edit into a
 * visible conflict instead of a silent overwrite.
 */
function DeviceForm({ device, meta }: FormProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.resolvedLanguage ?? 'en-US';
  const navigate = useNavigate();
  const session = useCurrentSession();
  const mode = device === null ? 'create' : 'update';

  const keys = formKeys(meta, mode);
  const fields = formFields(meta, session.user.role);
  const types = Object.fromEntries(meta.columns.map((column) => [column.api_key, column.type]));
  const columns = new Map(meta.columns.map((column) => [column.api_key, column]));
  const initial = initialFormValues(keys, device, fields, types);
  const baseCompany = device?.company ?? meta.companies[0] ?? '';

  // Cheap to rebuild (it only declares rules); a memo would have to track the
  // column and field lists by value for no measurable gain.
  const schema = buildDeviceSchema(keys, columns, fields, { requireSn: requireSn(meta) });

  const form = useForm<DeviceFormModel>({
    defaultValues: { values: initial.values, custom: initial.custom, company: baseCompany },
    resolver: zodResolver(schema),
    mode: 'onSubmit',
  });
  const { control, handleSubmit, setError, formState } = form;
  const [conflict, setConflict] = useState(false);

  const createDevice = useCreateDevice();
  const updateDevice = useUpdateDevice(device?.id ?? null);
  const pending = createDevice.isPending || updateDevice.isPending;

  const tabs = useMemo(() => formTabs(keys), [keys]);
  const [activeTab, setActiveTab] = useState(tabs[0]?.id ?? 'more');
  // The server may answer `422` for a field on another tab; switching there makes
  // the message findable instead of invisible.
  const tabForProblem = (key: string): string | null => tabs.find((tab) => tab.keys.includes(key))?.id ?? null;

  const onSubmit = async (model: DeviceFormModel) => {
    setConflict(false);
    const body = buildDevicePayload(model.values, model.custom, fields);
    if (mode === 'create' && model.company !== '') {
      body.company = model.company;
    }
    if (mode === 'update' && device?.updated_at != null) {
      body.expected_updated_at = device.updated_at;
    }

    try {
      const saved = mode === 'create' ? await createDevice.mutateAsync(body) : await updateDevice.mutateAsync(body);
      void navigate({ to: '/devices/$deviceId', params: { deviceId: String(saved.id) } });
    } catch (error) {
      if (error instanceof PanelApiError) {
        if (error.isStale) {
          setConflict(true);
          return;
        }
        if (error.isValidation) {
          let firstTab: string | null = null;
          for (const [key, code] of Object.entries(error.errors)) {
            const [root, fieldKey] = key.includes('.') ? (key.split('.') as [string, string]) : ['values', key];
            const path = root === 'custom' ? (`custom.${fieldKey}` as const) : (`values.${fieldKey}` as const);
            setError(path, { type: 'server', message: describeError(code) });
            firstTab = firstTab ?? tabForProblem(fieldKey);
          }
          if (firstTab !== null) {
            setActiveTab(firstTab);
          }
          return;
        }
      }
      // Transport/role errors are already toasted by the mutation hooks.
    }
  };

  const describeError = (code: string): string => {
    const label = ERROR_KEYS[code];
    return label === undefined ? code : t(label);
  };

  // The summary lists every message react-hook-form holds, client or server.
  const allErrors = { ...(formState.errors.values ?? {}), ...(formState.errors.custom ?? {}) } as Record<
    string,
    { message?: string }
  >;
  const serverErrors = Object.entries(allErrors).filter(([, value]) => value?.message !== undefined);

  return (
    <form className="flex flex-col gap-4" onSubmit={(event) => void handleSubmit(onSubmit)(event)} noValidate>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <Link to="/devices" className="text-sm text-[var(--color-accent)]">
            {t('back_to_list')}
          </Link>
          <h1 className="m-0 text-xl font-semibold">{mode === 'create' ? t('ui_new_device') : t('ui_edit_device')}</h1>
        </div>
        <div className="flex items-center gap-2">
          <Link
            to="/devices"
            className="inline-flex items-center rounded-[var(--radius-md)] border border-[var(--color-border)] px-3 py-1.5 text-sm"
          >
            {t('cancel')}
          </Link>
          <Button type="submit" variant="primary" disabled={pending}>
            {pending ? <Spinner className="size-4" /> : null}
            {mode === 'create' ? t('create_asset') : t('save_changes')}
          </Button>
        </div>
      </div>

      {conflict ? (
        <Alert
          tone="warning"
          title={t('ui_stale_record')}
          action={<Button onClick={() => window.location.reload()}>{t('ui_reload')}</Button>}
        />
      ) : null}

      {serverErrors.length > 0 ? (
        <Alert tone="danger" title={t('validation_failed')}>
          <ul className="m-0 list-disc ps-5">
            {serverErrors.map(([key, value]) => (
              <li key={key}>
                {columns.get(key)?.label ?? key}: {value.message}
              </li>
            ))}
          </ul>
        </Alert>
      ) : null}

      <div className="card p-4">
        <div
          role="tablist"
          aria-label={t('ui_form_tabs')}
          className="mb-3 flex flex-wrap gap-1 border-b border-[var(--color-border)] pb-2"
        >
          {tabs.map((tab) => (
            <button
              key={tab.id}
              type="button"
              role="tab"
              id={`form-tab-${tab.id}`}
              aria-selected={activeTab === tab.id}
              aria-controls={`form-panel-${tab.id}`}
              className={`rounded-[var(--radius-md)] px-2.5 py-1 text-sm ${
                activeTab === tab.id
                  ? 'bg-[var(--color-surface-3)] font-medium'
                  : 'text-[var(--color-muted)] hover:bg-[var(--color-surface-2)]'
              }`}
              onClick={() => setActiveTab(tab.id)}
            >
              {t(`ui_form_tab_${tab.id}`)}
            </button>
          ))}
        </div>

        {tabs.map((tab) => (
          <div
            key={tab.id}
            role="tabpanel"
            id={`form-panel-${tab.id}`}
            aria-labelledby={`form-tab-${tab.id}`}
            hidden={activeTab !== tab.id}
            className="grid gap-3 sm:grid-cols-2"
          >
            {tab.keys.map((key) => {
              const column = columns.get(key);
              const id = `field-${key.replace(/\./g, '-')}`;
              const path = `values.${key}` as const;
              const message = (formState.errors.values as Record<string, { message?: string } | undefined>)?.[key]
                ?.message;
              return (
                <div key={key} className="flex flex-col gap-1">
                  <label htmlFor={id} className="text-xs font-medium text-[var(--color-muted)]">
                    {column === undefined ? key : t(column.label_key, { defaultValue: column.label })}
                  </label>
                  <Controller
                    control={control}
                    name={path}
                    render={({ field }) => (
                      <DeviceControl
                        id={id}
                        value={field.value as FormValue}
                        column={column}
                        meta={meta}
                        invalid={message !== undefined}
                        onChange={field.onChange}
                      />
                    )}
                  />
                  {message === undefined ? null : <p className="m-0 text-xs text-[var(--color-danger)]">{message}</p>}
                </div>
              );
            })}
          </div>
        ))}
      </div>

      {mode === 'create' && meta.companies.length > 0 ? (
        <div className="card p-4">
          <Controller
            control={control}
            name="company"
            render={({ field }) => (
              <div className="flex flex-col gap-1">
                <label htmlFor="field-company" className="text-xs font-medium text-[var(--color-muted)]">
                  {t('col_company')}
                </label>
                <Select id="field-company" value={field.value} onChange={(event) => field.onChange(event.target.value)}>
                  {meta.companies.map((company) => (
                    <option key={company} value={company}>
                      {company}
                    </option>
                  ))}
                </Select>
              </div>
            )}
          />
        </div>
      ) : null}

      {fields.length > 0 ? (
        <div className="card flex flex-col gap-3 p-4">
          <h2 className="m-0 text-base font-semibold">{t('custom_fields')}</h2>
          <div className="grid gap-3 sm:grid-cols-2">
            {fields.map((field) => {
              const id = `field-custom-${field.key}`;
              const path = `custom.${field.key}` as const;
              const message = (
                formState.errors.custom as Record<string, { message?: string } | undefined> | undefined
              )?.[field.key]?.message;
              return (
                <div key={field.key} className="flex flex-col gap-1">
                  <label htmlFor={id} className="text-xs font-medium text-[var(--color-muted)]">
                    {field.label_i18n?.[locale] ?? field.label}
                    {field.required ? ' *' : ''}
                  </label>
                  <Controller
                    control={control}
                    name={path}
                    render={({ field: control_ }) => (
                      <DeviceControl
                        id={id}
                        value={control_.value as FormValue}
                        field={field}
                        meta={meta}
                        invalid={message !== undefined}
                        onChange={control_.onChange}
                      />
                    )}
                  />
                  {message === undefined ? null : <p className="m-0 text-xs text-[var(--color-danger)]">{message}</p>}
                </div>
              );
            })}
          </div>
        </div>
      ) : null}
    </form>
  );
}

/**
 * `/app/devices/new` and `/app/devices/:id/edit`.
 *
 * The device route is optional: `/devices/new` has no `deviceId`, so a missing
 * param is what selects create mode.
 */
export function DeviceFormPage() {
  const { t } = useTranslation();
  const params = useParams({ strict: false }) as { deviceId?: string };
  const rawId = params.deviceId ?? '';
  const id = /^\d+$/.test(rawId) ? Number.parseInt(rawId, 10) : null;
  const isEdit = id !== null;

  const metaQuery = useMeta();
  const deviceQuery = useDevice(isEdit ? id : null);

  if (metaQuery.isPending || (isEdit && deviceQuery.isPending)) {
    return (
      <div className="flex items-center gap-2 text-sm text-[var(--color-muted)]">
        <Spinner /> {t('ui_loading')}
      </div>
    );
  }

  if (metaQuery.isError || (isEdit && deviceQuery.isError)) {
    return (
      <Alert
        title={t('ui_api_unavailable')}
        action={<Button onClick={() => void metaQuery.refetch()}>{t('ui_retry')}</Button>}
      >
        <Link to="/devices" className="text-[var(--color-accent)]">
          {t('back_to_list')}
        </Link>
      </Alert>
    );
  }

  const device = isEdit ? (deviceQuery.data ?? null) : null;
  if (isEdit && device === null) {
    return (
      <Alert title={t('asset_not_found')}>
        <Link to="/devices" className="text-[var(--color-accent)]">
          {t('back_to_list')}
        </Link>
      </Alert>
    );
  }

  return (
    <DeviceForm
      key={device === null ? 'create' : `edit-${device.id}-${device.updated_at ?? ''}`}
      device={device}
      meta={metaQuery.data}
    />
  );
}
