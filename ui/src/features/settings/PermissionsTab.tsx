/**
 * Permissions tab — the persisted role × capability and role × field matrix.
 *
 * Superadmin is shown but never editable: it is the recovery role. A save sends
 * the complete matrix, so clearing a checkbox is an explicit revocation rather
 * than "leave whatever was stored". The backend deletes rows that match the
 * built-in defaults, so future capabilities inherit their intended default.
 */

import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Alert, Button, Checkbox, Spinner } from '@/components/ui';

import type { PermissionsInput, PermissionsPayload } from './api';
import { usePermissionMutations, usePermissions } from './useSettings';

const TH = 'px-2 py-1 text-start text-xs font-semibold uppercase tracking-wide text-[var(--color-muted)]';
const TD = 'px-2 py-1 text-sm';
const FIELD_LABELS: Record<string, string> = {
  SN: 'col_sn',
  Hostname: 'col_hostname',
  Manufacturer: 'col_manufacturer',
  OS: 'col_os',
  OSVersion: 'col_os_version',
  Mobile: 'col_mobile',
  Asset: 'col_asset',
  Status: 'col_status',
  UserEmail: 'col_user_email',
  BYOD: 'col_byod',
  Warranty: 'col_warranty',
  PurchaseDate: 'col_purchase_date',
  LastSeen: 'col_last_seen',
  CypherID: 'col_cypher_id',
  CypherKey: 'col_cypher_key',
  CPUs: 'col_cpus',
  HDs: 'col_hds',
  HDsTypes: 'col_hds_types',
  HDsSpacesGB: 'col_hds_spaces_gb',
  NetworkAdapters: 'col_network_adapters',
  MACAddresses: 'col_mac_addresses',
  ESETComponents: 'col_eset_components',
  PrimaryLocalIP: 'col_primary_local_ip',
  PrimaryRemoteIP: 'col_primary_remote_ip',
  Notes: 'col_notes',
  source: 'col_source',
  source_ref: 'col_source_ref',
  // Inventory-agent block (`asset_agent_columns()`). A missing entry is only a
  // cosmetic loss — the row falls back to the English `label` the server sends —
  // so the map is complete rather than generated.
  AgentHostname: 'col_agent_hostname',
  AgentOS: 'col_agent_os',
  AgentOSBuild: 'col_agent_os_build',
  AgentBIOS: 'col_agent_bios',
  AgentSecureBoot: 'col_agent_secure_boot',
  AgentManufacturer: 'col_agent_manufacturer',
  AgentModel: 'col_agent_model',
  AgentCPU: 'col_agent_cpu',
  AgentGPU: 'col_agent_gpu',
  AgentRAMGB: 'col_agent_ram_gb',
  AgentIP: 'col_agent_ip',
  AgentLoggedUser: 'col_agent_logged_user',
  AgentAllLoggedUsers: 'col_agent_all_logged_users',
  AgentExecutionUser: 'col_agent_execution_user',
  AgentAntivirus: 'col_agent_antivirus',
};

type Matrix = PermissionsPayload['matrix'];

function sameMatrix(a: Matrix, b: Matrix): boolean {
  return JSON.stringify(a) === JSON.stringify(b);
}

function toggle(list: string[], role: string, checked: boolean): string[] {
  if (checked) {
    return list.includes(role) ? list : [...list, role];
  }
  return list.filter((item) => item !== role);
}

export function PermissionsTab({ labelledBy }: { labelledBy: string }) {
  const { t } = useTranslation();
  const query = usePermissions(true);
  const mutation = usePermissionMutations();
  const [matrix, setMatrix] = useState<Matrix | null>(null);

  useEffect(() => {
    if (query.data !== undefined) {
      setMatrix(query.data.matrix);
    }
  }, [query.data]);

  if (query.isPending || (query.data !== undefined && matrix === null)) {
    return (
      <div className="flex items-center gap-2 text-sm text-[var(--color-muted)]">
        <Spinner /> {t('ui_loading')}
      </div>
    );
  }

  if (query.isError || matrix === null) {
    return (
      <Alert
        title={t('ui_api_unavailable')}
        action={<Button onClick={() => void query.refetch()}>{t('ui_retry')}</Button>}
      >
        {query.error instanceof Error ? query.error.message : null}
      </Alert>
    );
  }

  if (query.data === undefined) {
    return null;
  }
  const data = query.data;
  const roles = data.roles;
  const dirty = !sameMatrix(matrix, data.matrix);

  const setCapability = (role: string, capability: string, checked: boolean) => {
    setMatrix({
      ...matrix,
      capabilities: {
        ...matrix.capabilities,
        [role]: { ...(matrix.capabilities[role] ?? {}), [capability]: checked },
      },
    });
  };

  const setField = (field: string, role: string, checked: boolean) => {
    setMatrix({
      ...matrix,
      fields: {
        ...matrix.fields,
        [field]: toggle(matrix.fields[field] ?? [], role, checked),
      },
    });
  };

  const save = () => {
    const input: PermissionsInput = {
      capabilities: matrix.capabilities,
      fields: matrix.fields,
    };
    mutation.mutate(input);
  };

  return (
    <section aria-labelledby={labelledBy} className="flex flex-col gap-5">
      <div className="flex flex-wrap items-center gap-3">
        <p className="m-0 max-w-3xl text-sm text-[var(--color-muted)]">{t('set_permissions_intro')}</p>
        <Button className="ms-auto" variant="primary" disabled={!dirty || mutation.isPending} onClick={save}>
          {t('save_changes')}
        </Button>
        {mutation.isPending ? <Spinner /> : null}
      </div>

      <div className="flex flex-col gap-2">
        <h3 className="m-0 text-sm font-semibold">{t('set_permissions_capabilities')}</h3>
        <div className="card overflow-x-auto p-0">
          <table className="w-full border-collapse">
            <thead>
              <tr className="border-b border-[var(--color-border)]">
                <th className={TH}>{t('set_permissions_capability')}</th>
                {roles.map((role) => (
                  <th key={role} className={`${TH} text-center`}>
                    {t(`role_${role}`)}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.capabilities.map((capability, index) => {
                const previous = data.capabilities[index - 1];
                const showGroup = previous === undefined || previous.group !== capability.group;
                return (
                  <tr
                    key={capability.key}
                    className={`border-b border-[var(--color-border)] ${
                      showGroup ? 'border-t-2 border-t-[var(--color-border)]' : ''
                    }`}
                  >
                    <td className={TD}>
                      {showGroup ? (
                        <span className="mb-0.5 block text-xs font-semibold uppercase tracking-wide text-[var(--color-muted)]">
                          {t(`perm_group_${capability.group}`)}
                        </span>
                      ) : null}
                      <span className="flex flex-col">
                        <span>{t(capability.label_key)}</span>
                        <span className="font-mono text-xs text-[var(--color-muted)]">{capability.key}</span>
                      </span>
                    </td>
                    {roles.map((role) => (
                      <td key={role} className={`${TD} text-center`}>
                        <Checkbox
                          label=""
                          aria-label={`${t(capability.label_key)}: ${t(`role_${role}`)}`}
                          checked={matrix.capabilities[role]?.[capability.key] === true}
                          disabled={role === 'superadmin'}
                          onChange={(event) => setCapability(role, capability.key, event.target.checked)}
                        />
                      </td>
                    ))}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>

      <div className="flex flex-col gap-2">
        <h3 className="m-0 text-sm font-semibold">{t('set_permissions_fields')}</h3>
        <p className="m-0 text-sm text-[var(--color-muted)]">{t('set_permissions_fields_hint')}</p>
        <div className="card overflow-x-auto p-0">
          <table className="w-full border-collapse">
            <thead>
              <tr className="border-b border-[var(--color-border)]">
                <th className={TH}>{t('set_permissions_field')}</th>
                {roles.map((role) => (
                  <th key={role} className={`${TH} text-center`}>
                    {t(`role_${role}`)}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.fields.map((field) => {
                const labelKey = field.custom ? null : FIELD_LABELS[field.key];
                return (
                  <tr key={field.key} className="border-b border-[var(--color-border)] last:border-0">
                    <td className={TD}>
                      <span className="flex flex-col">
                        <span>{labelKey === null || labelKey === undefined ? field.label : t(labelKey)}</span>
                        <span className="font-mono text-xs text-[var(--color-muted)]">
                          {field.custom ? t('set_permissions_custom') : field.key}
                        </span>
                      </span>
                    </td>
                    {roles.map((role) => (
                      <td key={role} className={`${TD} text-center`}>
                        <Checkbox
                          label=""
                          aria-label={`${field.label}: ${t(`role_${role}`)}`}
                          checked={(matrix.fields[field.key] ?? []).includes(role)}
                          disabled={role === 'superadmin'}
                          onChange={(event) => setField(field.key, role, event.target.checked)}
                        />
                      </td>
                    ))}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
    </section>
  );
}
