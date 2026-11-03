import { Link, useParams } from '@tanstack/react-router';
import { useTranslation } from 'react-i18next';

import { Alert, Button, Spinner } from '@/components/ui';
import { formatBytes, formatDateTime } from '@/lib/format';

import type { DeviceDetail, MetaPayload } from './api';
import { cellContext, cellValue, GridCell } from './columns';
import { useDevice, useMeta } from './useDevices';

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
          <GridCell
            value={cellValue(device, column)}
            columnKey={column.key}
            type={column.type}
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
            {...context}
          />
        </Row>
      ))}
    </dl>
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
        <p className="m-0">
          {deviceQuery.error instanceof Error ? deviceQuery.error.message : null}
        </p>
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
      </div>

      <div className="card p-4">
        <DetailFields device={device} meta={metaQuery.data} />
      </div>

      <div className="card p-4">
        <h2 className="m-0 text-base font-semibold">{t('files_title')}</h2>
        {device.files.length === 0 ? (
          <p className="m-0 mt-2 text-sm text-[var(--color-muted)]">{t('no_files')}</p>
        ) : (
          <ul className="m-0 mt-2 list-none p-0">
            {device.files.map((file) => (
              <li
                key={file.id}
                className="flex flex-wrap items-baseline justify-between gap-2 border-b border-[var(--color-border)] py-2 last:border-b-0"
              >
                <span className="min-w-0 break-all">{file.name}</span>
                <span className="text-xs whitespace-nowrap text-[var(--color-muted)]">
                  {formatBytes(file.size, locale)} · {formatDateTime(file.uploaded_at, locale)}
                </span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  );
}
