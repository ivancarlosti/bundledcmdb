import { Link, useNavigate, useParams } from '@tanstack/react-router';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Alert, Button, Spinner } from '@/components/ui';
import { useCurrentSession } from '@/features/auth/SessionContext';
import { formatBytes, formatDateTime } from '@/lib/format';

import type { DeviceDetail, MetaPayload } from './api';
import { deviceFileDownloadUrl } from './api';
import { cellContext, cellValue, GridCell } from './columns';
import { useDeleteDevice, useDeleteDeviceFile, useRestoreDevice, useUploadDeviceFile } from './useDeviceMutations';
import { useDevice, useDeviceEvents, useMeta } from './useDevices';

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5 border-b border-[var(--color-border)] py-2 last:border-b-0 sm:flex-row sm:items-baseline sm:gap-4">
      <dt className="w-full shrink-0 text-xs font-medium text-[var(--color-muted)] sm:w-52">{label}</dt>
      <dd className="m-0 min-w-0 break-words">{children}</dd>
    </div>
  );
}

function DetailFields({ device, meta }: { device: DeviceDetail; meta: MetaPayload }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.resolvedLanguage ?? 'en-US';
  const context = cellContext(meta, t, locale);

  return (
    <dl className="m-0">
      {meta.columns.map((column) => (
        <Row key={column.key} label={t(column.label_key, { defaultValue: column.label })}>
          <GridCell value={cellValue(device, column)} columnKey={column.key} type={column.type} {...context} />
        </Row>
      ))}
      {meta.fields.map((field) => (
        <Row key={`custom:${field.key}`} label={field.label_i18n?.[locale] ?? field.label}>
          <GridCell
            value={device.custom?.[field.key] ?? null}
            columnKey={`custom:${field.key}`}
            type={field.type}
            {...context}
          />
        </Row>
      ))}
    </dl>
  );
}

/** Edit / delete / restore actions, gated by the server-published capabilities. */
function DeviceActions({ device }: { device: DeviceDetail }) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const session = useCurrentSession();
  const deleteDevice = useDeleteDevice();
  const restoreDevice = useRestoreDevice();
  const trashed = device.deleted_at !== null;

  const onDelete = async (hard: boolean) => {
    const message = hard ? t('ui_confirm_hard_delete') : t('delete_confirm');
    if (!window.confirm(message)) {
      return;
    }
    try {
      await deleteDevice.mutateAsync({ id: device.id, hard });
      void navigate({ to: '/devices' });
    } catch {
      // the mutation already toasted the error
    }
  };

  return (
    <div className="flex flex-wrap items-center gap-2">
      {session.capabilities.can_write && !trashed ? (
        <Link
          to="/devices/$deviceId/edit"
          params={{ deviceId: String(device.id) }}
          className="inline-flex items-center rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-1.5 text-sm font-medium hover:bg-[var(--color-surface-2)]"
        >
          {t('edit')}
        </Link>
      ) : null}
      {session.capabilities.can_admin && trashed ? (
        <Button variant="primary" disabled={restoreDevice.isPending} onClick={() => restoreDevice.mutate(device.id)}>
          {t('ui_restore')}
        </Button>
      ) : null}
      {session.capabilities.can_admin && !trashed ? (
        <Button variant="danger" disabled={deleteDevice.isPending} onClick={() => void onDelete(false)}>
          {t('delete')}
        </Button>
      ) : null}
      {session.capabilities.can_superadmin ? (
        <Button variant="ghost" disabled={deleteDevice.isPending} onClick={() => void onDelete(true)}>
          {t('ui_hard_delete')}
        </Button>
      ) : null}
    </div>
  );
}

/** Attachment list + upload/remove controls. */
function FilesSection({ device }: { device: DeviceDetail }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.resolvedLanguage ?? 'en-US';
  const session = useCurrentSession();
  const inputRef = useRef<HTMLInputElement>(null);
  const [pendingName, setPendingName] = useState<string | null>(null);
  const upload = useUploadDeviceFile(device.id);
  const remove = useDeleteDeviceFile(device.id);

  const onFileChange = async (file: File | null) => {
    if (file === null) {
      return;
    }
    setPendingName(file.name);
    try {
      await upload.mutateAsync(file);
    } catch {
      // the mutation already toasted the error
    } finally {
      setPendingName(null);
      if (inputRef.current !== null) {
        inputRef.current.value = '';
      }
    }
  };

  return (
    <div className="card p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="m-0 text-base font-semibold">{t('files_title')}</h2>
        {session.capabilities.can_write && session.features.attachments ? (
          <div className="flex items-center gap-2">
            <input
              ref={inputRef}
              type="file"
              aria-label={t('upload_title')}
              className="max-w-[14rem] text-xs file:me-2 file:rounded-[var(--radius-md)] file:border file:border-[var(--color-border)] file:bg-[var(--color-surface-2)] file:px-2 file:py-1 file:text-xs"
              onChange={(event) => void onFileChange(event.target.files?.[0] ?? null)}
            />
            {upload.isPending ? (
              <span className="flex items-center gap-1 text-xs text-[var(--color-muted)]">
                <Spinner className="size-3" /> {t('ui_uploading')}
                {pendingName === null ? null : <span className="max-w-[12ch] truncate">{pendingName}</span>}
              </span>
            ) : null}
          </div>
        ) : null}
      </div>

      {device.files.length === 0 ? (
        <p className="m-0 mt-2 text-sm text-[var(--color-muted)]">{t('no_files')}</p>
      ) : (
        <ul className="m-0 mt-2 list-none p-0">
          {device.files.map((file) => (
            <li
              key={file.id}
              className="flex flex-wrap items-baseline justify-between gap-2 border-b border-[var(--color-border)] py-2 last:border-b-0"
            >
              <a
                href={deviceFileDownloadUrl(device.id, file.id)}
                className="min-w-0 break-all text-[var(--color-accent)] hover:underline"
              >
                {file.name}
              </a>
              <span className="flex items-center gap-3 text-xs whitespace-nowrap text-[var(--color-muted)]">
                {formatBytes(file.size, locale)} · {formatDateTime(file.uploaded_at, locale)}
                {session.capabilities.can_admin ? (
                  <button
                    type="button"
                    className="text-[var(--color-danger)] hover:underline"
                    disabled={remove.isPending}
                    onClick={() => {
                      if (window.confirm(t('ui_confirm_remove_file'))) {
                        remove.mutate(file.id);
                      }
                    }}
                  >
                    {t('ui_remove_file')}
                  </button>
                ) : null}
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/**
 * Audit trail of the device (`GET /devices/{id}/events`).
 *
 * Field names in `changes` are schema columns, so they are labelled with the
 * grid's own `col_*` translation (`meta.columns`) — one dictionary for the whole
 * panel. Rendered as a plain list: it is a record, not something to interact with.
 */
function HistorySection({ device, meta }: { device: DeviceDetail; meta: MetaPayload }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.resolvedLanguage ?? 'en-US';
  const eventsQuery = useDeviceEvents(device.id);
  const events = eventsQuery.data ?? [];
  const labelFor = (column: string): string => {
    const match = meta.columns.find((entry) => entry.key === column);
    return match === undefined ? column : t(match.label_key, { defaultValue: match.label });
  };

  return (
    <div className="card p-4">
      <h2 className="m-0 text-base font-semibold">{t('ui_history')}</h2>

      {eventsQuery.isPending ? (
        <p className="m-0 mt-2 flex items-center gap-2 text-sm text-[var(--color-muted)]">
          <Spinner className="size-3" /> {t('ui_loading')}
        </p>
      ) : events.length === 0 ? (
        <p className="m-0 mt-2 text-sm text-[var(--color-muted)]">{t('ui_history_empty')}</p>
      ) : (
        <ol className="m-0 mt-2 list-none p-0" data-testid="device-history">
          {events.map((event) => (
            <li key={event.id} className="border-b border-[var(--color-border)] py-2 last:border-b-0">
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="text-sm font-medium">
                  {t(`ui_ev_${event.action}`, { defaultValue: event.action })}
                </span>
                <span className="text-xs whitespace-nowrap text-[var(--color-muted)]">
                  {formatDateTime(event.created_at, locale)} · {event.actor}
                </span>
              </div>
              {Object.keys(event.changes).length === 0 ? null : (
                <ul className="m-0 mt-1 list-none p-0 text-xs text-[var(--color-muted)]">
                  {Object.entries(event.changes).map(([column, [oldValue, newValue]]) => (
                    <li key={column}>
                      <span className="font-medium">{labelFor(column)}</span>:{' '}
                      {oldValue === null || oldValue === '' ? '∅' : oldValue} →{' '}
                      {newValue === null || newValue === '' ? '∅' : newValue}
                    </li>
                  ))}
                </ul>
              )}
            </li>
          ))}
        </ol>
      )}
    </div>
  );
}

/** `/app/devices/:id` — every published column, the custom values, the files. */
export function DeviceDetailPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.resolvedLanguage ?? 'en-US';
  const params = useParams({ strict: false }) as { deviceId?: string };
  const rawId = params.deviceId ?? '';
  const id = /^\d+$/.test(rawId) ? Number.parseInt(rawId, 10) : null;

  const deviceQuery = useDevice(id);
  const metaQuery = useMeta();

  if (id === null) {
    return (
      <Alert title={t('asset_not_found')}>
        <Link to="/devices" className="text-[var(--color-accent)]">
          {t('back_to_list')}
        </Link>
      </Alert>
    );
  }

  if (deviceQuery.isPending || metaQuery.isPending) {
    return (
      <div className="flex items-center gap-2 text-sm text-[var(--color-muted)]">
        <Spinner /> {t('ui_loading')}
      </div>
    );
  }

  if (deviceQuery.isError || metaQuery.isError) {
    return (
      <Alert
        title={deviceQuery.isError ? t('asset_not_found') : t('ui_api_unavailable')}
        action={
          <div className="flex gap-2">
            <Button onClick={() => void deviceQuery.refetch()}>{t('ui_retry')}</Button>
            <Link
              to="/devices"
              className="inline-flex items-center rounded-[var(--radius-md)] border border-[var(--color-border)] px-3 py-1.5 text-sm"
            >
              {t('back_to_list')}
            </Link>
          </div>
        }
      >
        <p className="m-0">{deviceQuery.error instanceof Error ? deviceQuery.error.message : null}</p>
      </Alert>
    );
  }

  const device = deviceQuery.data;
  const title = device.sn ?? device.hostname ?? `#${device.id}`;

  return (
    <section className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <Link to="/devices" className="text-sm text-[var(--color-accent)]">
            {t('back_to_list')}
          </Link>
          <h1 className="m-0 text-xl font-semibold">{title}</h1>
          <p className="m-0 text-sm text-[var(--color-muted)]">
            {device.hostname ?? '—'}
            {device.company !== null && device.company !== '' ? ` · ${device.company}` : ''}
          </p>
        </div>
        <DeviceActions device={device} />
      </div>

      {device.deleted_at !== null ? (
        <Alert tone="warning" title={t('ui_trashed')}>
          <p className="m-0">
            {t('col_deleted_at')}: {formatDateTime(device.deleted_at, locale)}
          </p>
        </Alert>
      ) : null}

      <div className="card p-4">
        <DetailFields device={device} meta={metaQuery.data} />
      </div>

      <FilesSection device={device} />

      <HistorySection device={device} meta={metaQuery.data} />
    </section>
  );
}
