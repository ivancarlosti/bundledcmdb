import { Link, useNavigate, useParams } from '@tanstack/react-router';
import { useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { PanelApiError } from '@/api/client';
import { Alert, Button, Spinner } from '@/components/ui';
import { useCurrentSession } from '@/features/auth/SessionContext';
import { formatBytes, formatDateTime } from '@/lib/format';

import type { DeviceDetail, MetaPayload } from './api';
import { deviceFileDownloadUrl } from './api';
import { cellContext, cellValue, GridCell } from './columns';
import {
  DeleteReasonFields,
  deleteReasonLabelKey,
  EMPTY_DELETE_REASON,
  type DeleteReasonState,
} from './DeleteReasonFields';
import { deviceLabel } from './DuplicatePicker';
import { useDeleteDevice, useDeleteDeviceFile, useRestoreDevice, useUploadDeviceFile } from './useDeviceMutations';
import { useDevice, useDeviceEvents, useMeta } from './useDevices';

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5 border-b border-[var(--color-border)] py-2 last:border-b-0 sm:flex-row sm:items-baseline sm:gap-4">
      <dt className="w-full shrink-0 text-xs font-medium text-[var(--color-muted)] sm:w-52">{label}</dt>
      <dd className="m-0 min-w-0 break-words sm:flex-1">{children}</dd>
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
          <GridCell
            value={cellValue(device, column)}
            columnKey={column.key}
            type={column.type}
            truncate={false}
            {...context}
          />
        </Row>
      ))}
      {meta.fields.map((field) => (
        <Row key={`custom:${field.key}`} label={field.label_i18n?.[locale] ?? field.label}>
          <GridCell
            value={device.custom?.[field.key] ?? null}
            columnKey={`custom:${field.key}`}
            type={field.type}
            truncate={false}
            {...context}
          />
        </Row>
      ))}
    </dl>
  );
}

/**
 * Edit / delete / restore actions, gated by the server-published capabilities.
 *
 * Deleting asks why: the row survives in the trash, but the reason is the part
 * an auditor or a colleague needs ("was it a duplicate?"). A permanent delete
 * drops the row the reason would describe, so it keeps the plain confirm.
 */
function DeviceActions({ device, meta }: { device: DeviceDetail; meta: MetaPayload }) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const session = useCurrentSession();
  const deleteDevice = useDeleteDevice();
  const restoreDevice = useRestoreDevice();
  const trashed = device.deleted_at !== null;
  const permissions = session.capabilities.permissions;

  const [confirming, setConfirming] = useState(false);
  const [reason, setReason] = useState<DeleteReasonState>(EMPTY_DELETE_REASON);
  const [reasonError, setReasonError] = useState<string | null>(null);
  const [duplicateError, setDuplicateError] = useState<string | null>(null);

  const reasons = useMemo(
    () => meta.deleteReasons.map((option) => ({ value: option.value, label: t(option.label_key) })),
    [meta.deleteReasons, t],
  );

  const closeConfirm = () => {
    setConfirming(false);
    setReasonError(null);
    setDuplicateError(null);
  };

  const onSoftDelete = async () => {
    if (reason.reason === '') {
      setReasonError(t('ui_delete_reason_required'));
      return;
    }
    setReasonError(null);
    setDuplicateError(null);
    try {
      await deleteDevice.mutateAsync({
        id: device.id,
        reason: reason.reason,
        duplicateOf: reason.duplicateOf?.id ?? null,
      });
      void navigate({ to: '/devices' });
    } catch (error) {
      // The mutation already toasted the failure; the form keeps the field that
      // the server pointed at, so the answer does not have to be reconstructed
      // from the toast text.
      if (error instanceof PanelApiError) {
        if (error.errors.duplicate_of !== undefined) {
          setDuplicateError(error.message);
        } else if (error.errors.reason !== undefined) {
          setReasonError(error.message);
        }
      }
    }
  };

  const onHardDelete = async () => {
    if (!window.confirm(t('ui_confirm_hard_delete'))) {
      return;
    }
    try {
      await deleteDevice.mutateAsync({ id: device.id, hard: true });
      void navigate({ to: '/devices' });
    } catch {
      // the mutation already toasted the error
    }
  };

  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap items-center gap-2">
        {permissions['devices.update'] && !trashed ? (
          <Link
            to="/devices/$deviceId/edit"
            params={{ deviceId: String(device.id) }}
            className="inline-flex items-center rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-1.5 text-sm font-medium hover:bg-[var(--color-surface-2)]"
          >
            {t('edit')}
          </Link>
        ) : null}
        {permissions['devices.restore'] && trashed ? (
          <Button variant="primary" disabled={restoreDevice.isPending} onClick={() => restoreDevice.mutate(device.id)}>
            {t('ui_restore')}
          </Button>
        ) : null}
        {permissions['devices.delete'] && !trashed ? (
          <Button
            variant="danger"
            disabled={deleteDevice.isPending}
            aria-expanded={confirming}
            onClick={() => (confirming ? closeConfirm() : setConfirming(true))}
          >
            {t('delete')}
          </Button>
        ) : null}
        {permissions['devices.purge'] ? (
          <Button variant="ghost" disabled={deleteDevice.isPending} onClick={() => void onHardDelete()}>
            {t('ui_hard_delete')}
          </Button>
        ) : null}
      </div>

      {confirming ? (
        <div className="card flex flex-col gap-2 p-3" data-testid="delete-reason">
          <p className="m-0 text-sm font-medium">{t('delete_confirm')}</p>
          <DeleteReasonFields
            reasons={reasons}
            value={reason}
            onChange={setReason}
            deviceId={device.id}
            required
            error={reasonError}
            duplicateError={duplicateError}
          />
          <div className="flex flex-wrap gap-2">
            <Button variant="danger" disabled={deleteDevice.isPending} onClick={() => void onSoftDelete()}>
              {t('delete')}
            </Button>
            <Button variant="ghost" disabled={deleteDevice.isPending} onClick={closeConfirm}>
              {t('cancel')}
            </Button>
          </div>
        </div>
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
  const [progress, setProgress] = useState<number | null>(null);
  const upload = useUploadDeviceFile(device.id);
  const remove = useDeleteDeviceFile(device.id);

  const onFileChange = async (file: File | null) => {
    if (file === null) {
      return;
    }
    setPendingName(file.name);
    setProgress(0);
    try {
      await upload.mutateAsync({ file, onProgress: setProgress });
    } catch {
      // the mutation already toasted the error
    } finally {
      setPendingName(null);
      setProgress(null);
      if (inputRef.current !== null) {
        inputRef.current.value = '';
      }
    }
  };

  return (
    <div className="card p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="m-0 text-base font-semibold">{t('files_title')}</h2>
        {session.capabilities.permissions['devices.files.upload'] && session.features.attachments ? (
          <div className="flex items-center gap-2">
            <input
              ref={inputRef}
              type="file"
              aria-label={t('upload_title')}
              className="max-w-[14rem] text-xs file:me-2 file:rounded-[var(--radius-md)] file:border file:border-[var(--color-border)] file:bg-[var(--color-surface-2)] file:px-2 file:py-1 file:text-xs"
              onChange={(event) => void onFileChange(event.target.files?.[0] ?? null)}
            />
            {upload.isPending ? (
              <span className="flex items-center gap-1 text-xs text-[var(--color-muted)]" role="status">
                <Spinner className="size-3" /> {t('ui_uploading')}
                {progress === null ? null : <span>{Math.round(progress * 100)}%</span>}
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
                {session.capabilities.permissions['devices.files.delete'] ? (
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
    if (match !== undefined) {
      return t(match.label_key, { defaultValue: match.label });
    }
    // The deletion reason and its duplicate reference are not asset columns, so
    // they have no `col_*` entry to fall back on.
    if (column === 'deleted_reason') {
      return t('ui_delete_reason');
    }
    if (column === 'duplicate_of_id') {
      return t('ui_duplicate_of');
    }
    return column;
  };

  /** `duplicated` → "Duplicated"; every other recorded value is shown as stored. */
  const valueFor = (column: string, value: string | null): string => {
    if (value === null || value === '') {
      return '∅';
    }
    if (column !== 'deleted_reason') {
      return value;
    }
    const key = deleteReasonLabelKey(meta, value);
    return key === null ? value : t(key, { defaultValue: value });
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
                      <span className="font-medium">{labelFor(column)}</span>: {valueFor(column, oldValue)} →{' '}
                      {valueFor(column, newValue)}
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
  // `?? null` also covers a payload that predates 0014 (and the e2e fixtures),
  // where the keys are absent instead of NULL.
  const deletedReason = device.deleted_reason ?? null;
  const duplicateOfId = device.duplicate_of_id ?? null;
  const deletedReasonKey = deleteReasonLabelKey(metaQuery.data, deletedReason);
  const deletedReasonLabel =
    deletedReason === null
      ? null
      : deletedReasonKey === null
        ? deletedReason
        : t(deletedReasonKey, { defaultValue: deletedReason });

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
        <DeviceActions device={device} meta={metaQuery.data} />
      </div>

      {device.deleted_at !== null ? (
        <Alert tone="warning" title={t('ui_trashed')}>
          <p className="m-0">
            {t('col_deleted_at')}: {formatDateTime(device.deleted_at, locale)}
          </p>
          {deletedReasonLabel === null ? null : (
            <p className="m-0">{t('ui_trashed_reason', { reason: deletedReasonLabel })}</p>
          )}
          {duplicateOfId === null ? null : (
            <p className="m-0">
              {t('ui_duplicate_of')}:{' '}
              {device.duplicate_of === null || device.duplicate_of === undefined ? (
                // The kept asset was purged since, or belongs to another company:
                // the reference is still in the row, so it is shown as an id.
                t('ui_duplicate_of_missing', { id: duplicateOfId })
              ) : (
                <Link
                  to="/devices/$deviceId"
                  params={{ deviceId: String(device.duplicate_of.id) }}
                  className="text-[var(--color-accent)]"
                >
                  {deviceLabel(device.duplicate_of)}
                </Link>
              )}
            </p>
          )}
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
