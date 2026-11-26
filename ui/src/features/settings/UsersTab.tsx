/**
 * Users tab — tenant-scoped roster management.
 *
 * `users` has no surrogate key, so the e-mail *is* the identity:
 * `PATCH/DELETE /settings/users/{email}`. The server decides what the caller
 * may see and change (`scope`, `roles`, `manageable`):
 *
 *  - a superadmin sees every company and may assign any role;
 *  - an admin sees only their own company, may assign user/manager and cannot
 *    touch a row at or above their own rank (the API answers 403 either way, so
 *    the UI simply does not offer the control).
 *
 * The roster is returned in full by one endpoint, so the search and company
 * filter stay client-side: no extra round-trip while typing.
 */

import { useMemo, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';

import { PanelApiError } from '@/api/client';
import { Alert, Button, Field, Input, Select, Spinner } from '@/components/ui';

import { settingsErrorKey } from './settingsForm';
import { useSettingsUsers, useUserMutations } from './useSettings';

const TH = 'px-2 py-1 text-start text-xs font-semibold uppercase tracking-wide text-[var(--color-muted)]';
const TD = 'px-2 py-1 text-sm';
/** Sentinel for the "No company" option; company names cannot be empty. */
const NO_COMPANY = '__unassigned__';

type NewUserForm = { email: string; company: string; role: string };

export function UsersTab({ labelledBy }: { labelledBy: string }) {
  const { t } = useTranslation();
  const query = useSettingsUsers(true);
  const { create, update, remove } = useUserMutations();
  const [search, setSearch] = useState('');
  const [company, setCompany] = useState('');
  const [adding, setAdding] = useState(false);
  const [form, setForm] = useState<NewUserForm>({ email: '', company: '', role: 'user' });

  const users = query.data?.users;
  const scope = query.data?.scope;
  const roles = query.data?.roles ?? [];
  const registry = query.data?.companies ?? [];

  /** Registry names plus any company the visible rows carry. */
  const companyOptions = useMemo(() => {
    const names = new Set<string>(registry);
    for (const user of users ?? []) {
      if (user.company !== '') {
        names.add(user.company);
      }
    }
    return {
      names: [...names].sort((a, b) => a.localeCompare(b)),
      hasUnassigned: (users ?? []).some((user) => user.company === ''),
    };
  }, [registry, users]);

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

  const fieldErrors = create.error instanceof PanelApiError && create.error.isValidation ? create.error.errors : {};

  const errorLine = (field: string) => {
    const code = fieldErrors[field];
    if (code === undefined) {
      return null;
    }
    const key = settingsErrorKey(code);
    return <p className="m-0 text-xs text-[var(--color-danger)]">{key === null ? code : t(key)}</p>;
  };

  const openAdd = () => {
    const defaultRole = roles.includes('user') ? 'user' : (roles[0] ?? 'user');
    const defaultCompany = scope?.all_companies ? (registry[0] ?? '') : (scope?.company ?? '');
    setForm({ email: '', company: defaultCompany, role: defaultRole });
    create.reset();
    setAdding(true);
  };

  const closeAdd = () => {
    setAdding(false);
    setForm({ email: '', company: '', role: 'user' });
  };

  const submitAdd = (event: FormEvent) => {
    event.preventDefault();
    create.mutate({ email: form.email.trim(), company: form.company, role: form.role }, { onSuccess: closeAdd });
  };

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

  const total = users?.length ?? 0;

  return (
    <section aria-labelledby={labelledBy} className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2">
        <p className="m-0 text-sm text-[var(--color-muted)]">{t('set_users_intro')}</p>
        {query.data?.can_create ? (
          <Button className="ms-auto" variant="primary" onClick={openAdd}>
            {t('set_user_add')}
          </Button>
        ) : null}
      </div>

      {scope === undefined ? null : (
        <p className="m-0 text-sm text-[var(--color-muted)]">
          {scope.all_companies ? t('set_user_scope_all') : t('set_user_scope_company', { company: scope.company })}
        </p>
      )}

      {adding ? (
        <form onSubmit={submitAdd} className="card flex flex-col gap-3 p-4">
          <h3 className="m-0 text-sm font-semibold">{t('set_user_create')}</h3>
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="flex flex-col gap-1">
              <label htmlFor="set_user_new_email" className="text-xs font-medium text-[var(--color-muted)]">
                {t('set_user_email')}
              </label>
              <Input
                id="set_user_new_email"
                type="email"
                required
                value={form.email}
                onChange={(event) => setForm({ ...form, email: event.target.value })}
              />
              {errorLine('email')}
            </div>
            <div className="flex flex-col gap-1">
              <label htmlFor="set_user_new_company" className="text-xs font-medium text-[var(--color-muted)]">
                {t('set_key_company')}
              </label>
              {scope?.all_companies ? (
                <>
                  <Select
                    id="set_user_new_company"
                    value={form.company}
                    onChange={(event) => setForm({ ...form, company: event.target.value })}
                  >
                    <option value="">{t('set_user_no_company')}</option>
                    {registry.map((name) => (
                      <option key={name} value={name}>
                        {name}
                      </option>
                    ))}
                  </Select>
                  <p className="m-0 text-xs text-[var(--color-muted)]">{t('set_user_company_hint')}</p>
                </>
              ) : (
                <Input id="set_user_new_company" value={scope?.company ?? ''} disabled />
              )}
              {errorLine('company')}
            </div>
            <div className="flex flex-col gap-1">
              <label htmlFor="set_user_new_role" className="text-xs font-medium text-[var(--color-muted)]">
                {t('set_user_role')}
              </label>
              <Select
                id="set_user_new_role"
                value={form.role}
                onChange={(event) => setForm({ ...form, role: event.target.value })}
              >
                {roles.map((role) => (
                  <option key={role} value={role}>
                    {t(`role_${role}`)}
                  </option>
                ))}
              </Select>
              {errorLine('role')}
            </div>
          </div>
          <div className="flex items-center gap-2">
            <Button type="submit" variant="primary" disabled={create.isPending}>
              {t('set_user_create')}
            </Button>
            <Button variant="ghost" onClick={closeAdd}>
              {t('cancel')}
            </Button>
            {create.isPending ? <Spinner /> : null}
          </div>
        </form>
      ) : null}

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
              {companyOptions.names.map((name) => (
                <option key={name} value={name}>
                  {name}
                </option>
              ))}
              {companyOptions.hasUnassigned ? <option value={NO_COMPANY}>{t('set_user_no_company')}</option> : null}
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
              <th className={TH}>{t('set_actions')}</th>
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
                  {user.manageable ? (
                    <Select
                      aria-label={t('set_user_role')}
                      value={user.role}
                      disabled={update.isPending}
                      onChange={(event) => update.mutate({ email: user.email, input: { role: event.target.value } })}
                    >
                      {roles.map((role) => (
                        <option key={role} value={role}>
                          {t(`role_${role}`)}
                        </option>
                      ))}
                    </Select>
                  ) : (
                    <span className="text-sm" title={user.is_self ? t('set_err_self_role') : t('set_user_readonly')}>
                      {t(`role_${user.role}`)}
                    </span>
                  )}
                </td>
                <td className={TD}>
                  {user.manageable ? (
                    <Button
                      variant="ghost"
                      disabled={remove.isPending}
                      onClick={() => {
                        if (window.confirm(t('set_user_delete_confirm'))) {
                          remove.mutate(user.email);
                        }
                      }}
                    >
                      {t('set_user_delete')}
                    </Button>
                  ) : null}
                </td>
              </tr>
            ))}
            {visibleUsers.length === 0 ? (
              <tr>
                <td className={TD} colSpan={4}>
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
