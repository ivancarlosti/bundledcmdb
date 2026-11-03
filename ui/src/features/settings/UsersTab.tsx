/**
 * Users tab (superadmin only).
 *
 * `users` has no surrogate key, so the e-mail *is* the identity: the select
 * writes `PATCH /settings/users/{email}`. The caller's own row is disabled —
 * the server refuses a self-demotion with `409 self_role_change` and the UI
 * should not offer a button that cannot work.
 *
 * The roster is returned in full by one endpoint, so the search and company
 * filter stay client-side: no extra round-trip while typing.
 */

import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Alert, Button, Field, Input, Select, Spinner } from '@/components/ui';

import { useSettingsUsers, useUserMutations } from './useSettings';

const TH = 'px-2 py-1 text-start text-xs font-semibold uppercase tracking-wide text-[var(--color-muted)]';
const TD = 'px-2 py-1 text-sm';
/** Sentinel for the "No company" option; company names cannot be empty. */
const NO_COMPANY = '__unassigned__';

export function UsersTab({ labelledBy }: { labelledBy: string }) {
  const { t } = useTranslation();
  const query = useSettingsUsers(true);
  const mutation = useUserMutations();
  const [search, setSearch] = useState('');
  const [company, setCompany] = useState('');

  const users = query.data?.users;
  const companies = useMemo(() => {
    const list = users ?? [];
    return {
      names: [...new Set(list.map((user) => user.company).filter((name) => name !== ''))].sort((a, b) =>
        a.localeCompare(b),
      ),
      hasUnassigned: list.some((user) => user.company === ''),
    };
  }, [users]);

  const visibleUsers = useMemo(() => {
    const needle = search.trim().toLowerCase();
    return (users ?? []).filter((user) => {
      if (company === NO_COMPANY) {
        if (user.company !== '') {
          return false;
        }
      } else if (company !== '' && user.company !== company) {
        return false;
      }
      if (needle === '') {
        return true;
      }
      return [user.email, user.company, user.role, t(`role_${user.role}`)].some((value) =>
        value.toLowerCase().includes(needle),
      );
    });
  }, [company, search, t, users]);

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
  const total = users?.length ?? 0;

  return (
    <section aria-labelledby={labelledBy} className="flex flex-col gap-4">
      <p className="m-0 text-sm text-[var(--color-muted)]">{t('set_users_intro')}</p>

      <div className="card flex flex-wrap items-end gap-3 p-3">
        <Field label={t('set_user_search')} htmlFor="set_user_search" className="min-w-56 flex-1">
          <Input
            id="set_user_search"
            type="search"
            value={search}
            placeholder={t('set_user_search_placeholder')}
            onChange={(event) => setSearch(event.target.value)}
          />
        </Field>

        {total === 0 ? null : (
          <Field label={t('set_key_company')} htmlFor="set_user_company" className="min-w-40">
            <Select id="set_user_company" value={company} onChange={(event) => setCompany(event.target.value)}>
              <option value="">{t('set_user_all_companies')}</option>
              {companies.names.map((name) => (
                <option key={name} value={name}>
                  {name}
                </option>
              ))}
              {companies.hasUnassigned ? <option value={NO_COMPANY}>{t('set_user_no_company')}</option> : null}
            </Select>
          </Field>
        )}
      </div>

      <p aria-live="polite" className="m-0 text-sm text-[var(--color-muted)]">
        {t('set_user_summary', { shown: visibleUsers.length, total })}
      </p>

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
            {visibleUsers.map((user) => (
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
            {visibleUsers.length === 0 ? (
              <tr>
                <td className={TD} colSpan={3}>
                  {total === 0 ? t('set_user_empty') : t('set_user_no_results')}
                </td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </div>
    </section>
  );
}
