/**
 * Companies tab — the tenant registry (superadmin).
 *
 * A company is a name, not a parent record: `users`, `assets` and `api_keys`
 * keep their `company` string. The registry exists so a tenant can be created
 * before its first user/device, and so a delete can be refused with the usage
 * counts that block it.
 */

import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';

import { PanelApiError } from '@/api/client';
import { Alert, Button, Input, Spinner } from '@/components/ui';

import { settingsErrorKey } from './settingsForm';
import { useCompanies, useCompanyMutations } from './useSettings';

const TH = 'px-2 py-1 text-start text-xs font-semibold uppercase tracking-wide text-[var(--color-muted)]';
const TD = 'px-2 py-1 text-sm';

export function CompaniesTab({ labelledBy }: { labelledBy: string }) {
  const { t } = useTranslation();
  const query = useCompanies(true);
  const { create, remove } = useCompanyMutations();
  const [adding, setAdding] = useState(false);
  const [name, setName] = useState('');

  const fieldErrors = create.error instanceof PanelApiError && create.error.isValidation ? create.error.errors : {};
  const nameError = fieldErrors.name;
  const nameErrorKey = nameError === undefined ? null : settingsErrorKey(nameError);

  const closeAdd = () => {
    setAdding(false);
    setName('');
  };

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (name.trim() === '') {
      return;
    }
    create.mutate(name.trim(), { onSuccess: closeAdd });
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

  const companies = query.data?.companies ?? [];

  return (
    <section aria-labelledby={labelledBy} className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2">
        <p className="m-0 text-sm text-[var(--color-muted)]">{t('set_companies_intro')}</p>
        <Button
          className="ms-auto"
          variant="primary"
          onClick={() => {
            create.reset();
            setAdding(true);
          }}
        >
          {t('set_company_add')}
        </Button>
      </div>

      {adding ? (
        <form onSubmit={submit} className="card flex flex-col gap-3 p-4">
          <h3 className="m-0 text-sm font-semibold">{t('set_company_create')}</h3>
          <div className="flex flex-col gap-1">
            <label htmlFor="set_company_name" className="text-xs font-medium text-[var(--color-muted)]">
              {t('set_company_name')}
            </label>
            <Input id="set_company_name" value={name} autoFocus onChange={(event) => setName(event.target.value)} />
            <p className="m-0 text-xs text-[var(--color-muted)]">{t('set_company_name_hint')}</p>
            {nameError === undefined ? null : (
              <p className="m-0 text-xs text-[var(--color-danger)]">
                {nameErrorKey === null ? nameError : t(nameErrorKey)}
              </p>
            )}
          </div>
          <div className="flex items-center gap-2">
            <Button type="submit" variant="primary" disabled={create.isPending || name.trim() === ''}>
              {t('set_company_create')}
            </Button>
            <Button variant="ghost" onClick={closeAdd}>
              {t('cancel')}
            </Button>
            {create.isPending ? <Spinner /> : null}
          </div>
        </form>
      ) : null}

      <div className="card overflow-x-auto p-0">
        <table className="w-full border-collapse">
          <thead>
            <tr className="border-b border-[var(--color-border)]">
              <th className={TH}>{t('set_company_name')}</th>
              <th className={TH}>{t('set_company_usage_header')}</th>
              <th className={TH}>{t('set_actions')}</th>
            </tr>
          </thead>
          <tbody>
            {companies.map((company) => (
              <tr key={company.name} className="border-b border-[var(--color-border)] last:border-0">
                <td className={TD}>{company.name}</td>
                <td className={TD}>
                  {t('set_company_usage', {
                    users: company.users,
                    assets: company.assets,
                    keys: company.api_keys,
                  })}
                </td>
                <td className={TD}>
                  <Button
                    variant="ghost"
                    disabled={company.total > 0 || remove.isPending}
                    title={company.total > 0 ? t('set_company_in_use') : undefined}
                    onClick={() => {
                      if (window.confirm(t('set_company_delete_confirm', { name: company.name }))) {
                        remove.mutate(company.name);
                      }
                    }}
                  >
                    {t('set_delete')}
                  </Button>
                </td>
              </tr>
            ))}
            {companies.length === 0 ? (
              <tr>
                <td className={TD} colSpan={3}>
                  {t('set_company_empty')}
                </td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </div>
    </section>
  );
}
