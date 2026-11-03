/**
 * Users tab (superadmin only).
 *
 * `users` has no surrogate key, so the e-mail *is* the identity: the select
 * writes `PATCH /settings/users/{email}`. The caller's own row is disabled —
 * the server refuses a self-demotion with `409 self_role_change` and the UI
 * should not offer a button that cannot work.
 */

import { useTranslation } from 'react-i18next';

import { Alert, Button, Select, Spinner } from '@/components/ui';

import { useSettingsUsers, useUserMutations } from './useSettings';

const TH = 'px-2 py-1 text-start text-xs font-semibold uppercase tracking-wide text-[var(--color-muted)]';
const TD = 'px-2 py-1 text-sm';

export function UsersTab({ labelledBy }: { labelledBy: string }) {
  const { t } = useTranslation();
  const query = useSettingsUsers(true);
  const mutation = useUserMutations();

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

  const roles = query.data?.roles ?? [];

  return (
    <section aria-labelledby={labelledBy} className="flex flex-col gap-4">
      <p className="m-0 text-sm text-[var(--color-muted)]">{t('set_users_intro')}</p>

      <div className="card overflow-x-auto p-0">
        <table className="w-full border-collapse">
          <thead>
            <tr className="border-b border-[var(--color-border)]">
              <th className={TH}>{t('set_user_email')}</th>
              <th className={TH}>{t('set_key_company')}</th>
              <th className={TH}>{t('set_user_role')}</th>
            </tr>
          </thead>
          <tbody>
            {(query.data?.users ?? []).map((user) => (
              <tr key={user.email} className="border-b border-[var(--color-border)] last:border-0">
                <td className={TD}>
                  <span className="flex flex-wrap items-center gap-1.5">
                    {user.email}
                    {user.is_self ? (
                      <span className="text-xs text-[var(--color-muted)]">({t('set_user_self')})</span>
                    ) : null}
                  </span>
                </td>
                <td className={TD}>{user.company}</td>
                <td className={TD}>
                  <Select
                    aria-label={t('set_user_role')}
                    value={user.role}
                    disabled={user.is_self || mutation.isPending}
                    title={user.is_self ? t('set_err_self_role') : undefined}
                    onChange={(event) => mutation.mutate({ email: user.email, role: event.target.value })}
                  >
                    {roles.map((role) => (
                      <option key={role} value={role}>
                        {t(`role_${role}`)}
                      </option>
                    ))}
                  </Select>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
