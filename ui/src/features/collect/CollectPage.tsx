/**
 * `/app/collect` — experimental "Collect data" (download a machine report).
 *
 * The screen answers one question: "how do I get my computer into the inventory
 * without typing nine hardware facts?". So it offers exactly one action (generate
 * the file), states when the link dies, and explains the three steps the user has
 * to take. The raw token is never shown on screen — it lives in the downloaded
 * file only, and generating a new file cancels the previous one
 * (`lib/panel/collect.php`).
 *
 * The tab itself is hidden unless `features.collect` is on, but the screen still
 * checks: a bookmark, or a superadmin turning the setting off while the tab is
 * open, must land on a sentence rather than a failing request.
 */

import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Alert, Button, Field, Select, Spinner } from '@/components/ui';
import { useCurrentSession } from '@/features/auth/SessionContext';

import type { CollectFormat } from './api';
import { useCollect, useCreateCollectFile, useRevokeCollectFile } from './useCollect';

const MUTED = 'm-0 text-sm text-[var(--color-muted)]';

export function CollectPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.resolvedLanguage ?? 'en-US';
  const session = useCurrentSession();
  const query = useCollect();
  const create = useCreateCollectFile();
  const revoke = useRevokeCollectFile();
  const [format, setFormat] = useState<CollectFormat>('bat');

  if (!session.features.collect) {
    return <Alert title={t('collect_title')}>{t('collect_disabled')}</Alert>;
  }
  if (session.capabilities.permissions['devices.collect'] !== true) {
    return <Alert title={t('ui_err_forbidden')}>{t('collect_title')}</Alert>;
  }

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

  const status = query.data;
  const token = status.token;
  const expires = token?.expires_at ? new Date(token.expires_at) : null;
  const expired = expires !== null && expires.getTime() <= Date.now();

  /** A new file cancels the old link, so ask before doing it. */
  const generate = async () => {
    if (token !== null && !window.confirm(t('collect_confirm_regenerate'))) {
      return;
    }
    try {
      await create.mutateAsync(format);
    } catch {
      // The hook already toasted the failure.
    }
  };

  const doRevoke = async () => {
    if (!window.confirm(t('collect_confirm_revoke'))) {
      return;
    }
    try {
      await revoke.mutateAsync();
    } catch {
      // The hook already toasted the failure.
    }
  };

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <h1 className="m-0 text-xl font-semibold">{t('collect_title')}</h1>
        <p className={MUTED}>{t('collect_intro')}</p>
      </div>

      <section className="card flex flex-col gap-3 p-4">
        <h2 className="m-0 text-base font-semibold">{t('collect_your_file')}</h2>

        {token === null || expired ? (
          <p className={MUTED}>{expired ? t('collect_expired') : t('collect_no_file')}</p>
        ) : (
          <dl className="m-0 grid grid-cols-1 gap-1 text-sm sm:grid-cols-2">
            <div>
              <dt className="text-[var(--color-muted)]">{t('collect_expires')}</dt>
              <dd className="m-0">{expires?.toLocaleString(locale) ?? '—'}</dd>
            </div>
            <div>
              <dt className="text-[var(--color-muted)]">{t('collect_uses')}</dt>
              <dd className="m-0">{t('collect_uses_value', { count: token.uses })}</dd>
            </div>
            <div>
              <dt className="text-[var(--color-muted)]">{t('collect_last_used')}</dt>
              <dd className="m-0">
                {token.last_used_at === null
                  ? t('collect_never_used')
                  : `${new Date(token.last_used_at).toLocaleString(locale)} (${token.last_used_ip ?? '—'})`}
              </dd>
            </div>
            <div>
              <dt className="text-[var(--color-muted)]">{t('collect_file_name')}</dt>
              <dd className="m-0 break-all">{token.file_name}</dd>
            </div>
          </dl>
        )}

        <div className="flex flex-wrap items-end gap-3">
          <Field label={t('collect_format')} htmlFor="collect-format" className="min-w-44">
            <Select
              id="collect-format"
              value={format}
              onChange={(event) => setFormat(event.target.value === 'ps1' ? 'ps1' : 'bat')}
            >
              <option value="bat">{t('collect_format_bat')}</option>
              <option value="ps1">{t('collect_format_ps1')}</option>
            </Select>
          </Field>

          <Button disabled={create.isPending} onClick={() => void generate()}>
            {create.isPending ? <Spinner className="size-4" /> : null}
            {token === null || expired ? t('collect_generate') : t('collect_regenerate')}
          </Button>

          {token !== null && !expired ? (
            <Button variant="ghost" disabled={revoke.isPending} onClick={() => void doRevoke()}>
              {t('collect_revoke')}
            </Button>
          ) : null}
        </div>

        <p className={MUTED}>{t('collect_one_file_hint', { minutes: status.ttl_minutes })}</p>
        <p className={MUTED}>{t('collect_endpoint', { endpoint: status.endpoint })}</p>
      </section>

      <section className="card flex flex-col gap-2 p-4">
        <h2 className="m-0 text-base font-semibold">{t('collect_how_title')}</h2>
        <ol className="m-0 flex list-decimal flex-col gap-1 ps-5 text-sm">
          <li>{t('collect_how_step_1')}</li>
          <li>{t('collect_how_step_2')}</li>
          <li>{t('collect_how_step_3')}</li>
        </ol>
        <p className={MUTED}>{t('collect_how_note', { email: session.user.email })}</p>
        <p className={MUTED}>{t('collect_ps1_hint')}</p>
      </section>
    </div>
  );
}
