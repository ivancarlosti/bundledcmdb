import { Link, useNavigate, useParams } from '@tanstack/react-router';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';

import { PanelApiError } from '@/api/client';
import { Alert, Button, Checkbox, Input, Select, Spinner, Textarea } from '@/components/ui';
import { useCurrentSession } from '@/features/auth/SessionContext';
import { cn } from '@/lib/cn';

import type { DeviceDetail, MetaColumn, MetaField, MetaPayload } from './api';
import {
  buildDevicePayload,
  formFields,
  formKeys,
  initialFormValues,
  type FormValue,
  type FormValues,
} from './deviceForm';
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
      <Checkbox
        id={id}
        label={t('yes')}
        checked={value === true}
        onChange={(event) => onChange(event.target.checked)}
      />
    );
  }

  if (field !== undefined && field.options.length > 0) {
    return (
      <Select
        id={id}
        className={invalidClass}
        value={String(value)}
        onChange={(event) => onChange(event.target.value)}
      >
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
      <Select
        id={id}
        className={invalidClass}
        value={String(value)}
        onChange={(event) => onChange(event.target.value)}
      >
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
      <Select
        id={id}
        className={invalidClass}
        value={String(value)}
        onChange={(event) => onChange(event.target.value)}
      >
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
  const { t } = useTranslation();
  const navigate = useNavigate();
  const session = useCurrentSession();
  const mode = device === null ? 'create' : 'update';

  const keys = formKeys(meta, mode);
  const fields = formFields(meta, session.user.role);
  const types = Object.fromEntries(meta.columns.map((column) => [column.api_key, column.type]));
  const columns = new Map(meta.columns.map((column) => [column.api_key, column]));

  const initial = initialFormValues(keys, device, fields, types);
  const [values, setValues] = useState<FormValues>(initial.values);
  const [custom, setCustom] = useState<FormValues>(initial.custom);
  const [company, setCompany] = useState<string>(device?.company ?? meta.companies[0] ?? '');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [conflict, setConflict] = useState(false);

  const createDevice = useCreateDevice();
  const updateDevice = useUpdateDevice(device?.id ?? null);
  const pending = createDevice.isPending || updateDevice.isPending;

  const setValue = (key: string, value: FormValue) => {
    setValues((current) => ({ ...current, [key]: value }));
  };
  const setCustomValue = (key: string, value: FormValue) => {
    setCustom((current) => ({ ...current, [key]: value }));
  };

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setErrors({});
    setConflict(false);

    // Required custom fields are enforced here as well, so an obviously
    // incomplete form does not need a round-trip to be told what is missing.
    const missing: Record<string, string> = {};
    for (const field of fields) {
      if (!field.required) {
        continue;
      }
      const value = custom[field.key];
      if (value === undefined || value === '' || (typeof value === 'string' && value.trim() === '')) {
        missing[`custom.${field.key}`] = 'required';
      }
    }
    if (Object.keys(missing).length > 0) {
      setErrors(missing);
      return;
    }

    const body = buildDevicePayload(values, custom, fields);
    if (mode === 'create' && company !== '') {
      body.company = company;
    }
    if (mode === 'update' && device?.updated_at != null) {
      body.expected_updated_at = device.updated_at;
    }

    try {
      const saved = mode === 'create'
        ? await createDevice.mutateAsync(body)
        : await updateDevice.mutateAsync(body);
      void navigate({ to: '/devices/$deviceId', params: { deviceId: String(saved.id) } });
    } catch (error) {
      if (error instanceof PanelApiError) {
        if (error.isStale) {
          setConflict(true);
          return;
        }
        if (error.isValidation) {
          setErrors(error.errors);
          return;
        }
      }
      // Transport/role errors are already toasted by the mutation hooks.
    }
  };

  const errorFor = (key: string): string | null => {
    const code = errors[key];
    if (code === undefined) {
      return null;
    }
    const label = ERROR_KEYS[code];
    return label === undefined ? code : t(label);
  };

  return (
    <form className="flex flex-col gap-4" onSubmit={submit} noValidate>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <Link to="/devices" className="text-sm text-[var(--color-accent)]">
            {t('back_to_list')}
          </Link>
          <h1 className="m-0 text-xl font-semibold">
            {mode === 'create' ? t('ui_new_device') : t('ui_edit_device')}
          </h1>
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

      {Object.keys(errors).length > 0 ? (
        <Alert tone="danger" title={t('validation_failed')}>
          <ul className="m-0 list-disc ps-5">
            {Object.entries(errors).map(([key, code]) => (
              <li key={key}>
                {columns.get(key)?.label ?? key}:{' '}
                {ERROR_KEYS[code] === undefined ? code : t(ERROR_KEYS[code])}
              </li>
            ))}
          </ul>
        </Alert>
      ) : null}


      <div className="card grid gap-3 p-4 sm:grid-cols-2">
        {meta.companies.length > 0 && mode === 'create' ? (
          <div className="flex flex-col gap-1">
            <label htmlFor="field-company" className="text-xs font-medium text-[var(--color-muted)]">
              {t('col_company')}
            </label>
            <Select
              id="field-company"
              value={company}
              onChange={(event) => setCompany(event.target.value)}
            >
              <option value="">—</option>
              {meta.companies.map((name) => (
                <option key={name} value={name}>
                  {name}
                </option>
              ))}
            </Select>
            {errorFor('company') === null ? null : (
              <p className="m-0 text-xs text-[var(--color-danger)]">{errorFor('company')}</p>
            )}
          </div>
        ) : null}

        {keys.map((key) => {
          const column = columns.get(key);
          const label = column === undefined ? key : t(column.label_key, { defaultValue: column.label });
          const id = `field-${key}`;
          const error = errorFor(key);
          return (
            <div key={key} className={cn('flex flex-col gap-1', key === 'notes' && 'sm:col-span-2')}>
              <label htmlFor={id} className="text-xs font-medium text-[var(--color-muted)]">
                {label}
              </label>
              <DeviceControl
                id={id}
                value={values[key] ?? ''}
                column={column}
                meta={meta}
                invalid={error !== null}
                onChange={(value) => setValue(key, value)}
              />
              {error === null ? null : (
                <p className="m-0 text-xs text-[var(--color-danger)]">{error}</p>
              )}
            </div>
          );
        })}
      </div>

      {fields.length > 0 ? (
        <div className="card flex flex-col gap-3 p-4">
          <h2 className="m-0 text-base font-semibold">{t('custom_fields')}</h2>
          <div className="grid gap-3 sm:grid-cols-2">
            {fields.map((field) => {
              const id = `field-custom-${field.key}`;
              const error = errorFor(`custom.${field.key}`);
              return (
                <div key={field.key} className="flex flex-col gap-1">
                  <label htmlFor={id} className="text-xs font-medium text-[var(--color-muted)]">
                    {field.label_i18n?.[session.locale] ?? field.label}
                    {field.required ? ' *' : ''}
                  </label>
                  <DeviceControl
                    id={id}
                    value={custom[field.key] ?? ''}
                    field={field}
                    meta={meta}
                    invalid={error !== null}
                    onChange={(value) => setCustomValue(field.key, value)}
                  />
                  {error === null ? null : (
                    <p className="m-0 text-xs text-[var(--color-danger)]">{error}</p>
                  )}
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

