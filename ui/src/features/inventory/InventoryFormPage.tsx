/**
 * Create/edit form of one inventory kind.
 *
 * The field set follows `inventory_column_defs()`: the shared columns plus the ones
 * the kind owns (`model`/`serial`/`location`/`warranty_end` for an asset,
 * `version`/`license_expiry` for a system). The server rejects a field of the other
 * kind with `not_applicable`, so the form is the only place the split is user
 * visible.
 *
 * "Common usage" is the interesting control: checking it clears the assignment list
 * and disables the picker, because a shared item is assigned to nobody. The server
 * enforces the same rule, so this mirrors it rather than being the check itself.
 */

import { Link, useNavigate, useParams } from '@tanstack/react-router';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

import { PanelApiError } from '@/api/client';
import { Alert, Button, Checkbox, Field, Input, Select, Spinner, Textarea } from '@/components/ui';
import { useCurrentSession } from '@/features/auth/SessionContext';

import {
  inventoryEditKey,
  inventoryErrorKey,
  inventoryNewKey,
  inventoryRoute,
  type InventoryKind,
  type InventoryWriteBody,
} from './api';
import { UserMultiSelect } from './UserMultiSelect';
import { useInventoryItem, useInventoryMeta, useInventoryMutations } from './useInventory';

type FormState = {
  name: string;
  brand: string;
  category: string;
  model: string;
  version: string;
  serial: string;
  location: string;
  status: string;
  purchase_date: string;
  warranty_end: string;
  license_expiry: string;
  common_usage: boolean;
  notes: string;
  company: string;
};

const EMPTY: FormState = {
  name: '',
  brand: '',
  category: '',
  model: '',
  version: '',
  serial: '',
  location: '',
  status: '',
  purchase_date: '',
  warranty_end: '',
  license_expiry: '',
  common_usage: false,
  notes: '',
  company: '',
};

/** One text input plus its field error, if the server reported one. */
function TextField({
  id,
  label,
  value,
  type = 'text',
  error,
  onChange,
  className,
}: {
  id: string;
  label: string;
  value: string;
  type?: 'text' | 'date';
  error?: string;
  onChange: (value: string) => void;
  className?: string;
}) {
  return (
    <Field label={label} htmlFor={id} className={className}>
      <Input
        id={id}
        type={type}
        value={value}
        aria-invalid={error === undefined ? undefined : true}
        className={error === undefined ? undefined : 'border-[var(--color-danger)]'}
        onChange={(event) => onChange(event.target.value)}
      />
      {error === undefined ? null : <p className="m-0 text-xs text-[var(--color-danger)]">{error}</p>}
    </Field>
  );
}

export function InventoryFormPage({ kind }: { kind: InventoryKind }) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const params = useParams({ strict: false }) as { itemId?: string };
  const session = useCurrentSession();

  const itemId = params.itemId !== undefined && /^\d+$/.test(params.itemId) ? Number(params.itemId) : null;
  const isEdit = itemId !== null;

  const itemQuery = useInventoryItem(kind, itemId);
  const metaQuery = useInventoryMeta();
  const { create, update, remove } = useInventoryMutations(kind);

  const [form, setForm] = useState<FormState>(EMPTY);
  // The edit form is seeded once: a later re-render (meta arriving, a refetch)
  // must not overwrite what the user has already typed.
  const seeded = useRef(false);
  const [users, setUsers] = useState<string[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [failure, setFailure] = useState<string | null>(null);

  const statuses = metaQuery.data?.statuses ?? [];
  const defaultStatus = statuses[0]?.value ?? 'In Use';

  // Seed the form once the row arrives, and a new item on the first configured
  // status instead of leaving the select empty.
  useEffect(() => {
    if (!isEdit) {
      setForm((current) => (current.status === '' ? { ...current, status: defaultStatus } : current));
      return;
    }
    const row = itemQuery.data;
    if (row === undefined || seeded.current) {
      return;
    }
    seeded.current = true;
    setForm({
      name: row.name ?? '',
      brand: row.brand ?? '',
      category: row.category ?? '',
      model: row.model ?? '',
      version: row.version ?? '',
      serial: row.serial ?? '',
      location: row.location ?? '',
      status: row.status ?? defaultStatus,
      purchase_date: row.purchase_date ?? '',
      warranty_end: row.warranty_end ?? '',
      license_expiry: row.license_expiry ?? '',
      common_usage: row.common_usage,
      notes: row.notes ?? '',
      company: row.company ?? '',
    });
    setUsers(row.users);
  }, [isEdit, itemQuery.data, defaultStatus]);

  const patch = (next: Partial<FormState>) => setForm((current) => ({ ...current, ...next }));

  const setCommonUsage = (checked: boolean) => {
    // A shared item is assigned to nobody; the list is cleared here so the form
    // shows what the server will store.
    patch({ common_usage: checked });
    if (checked) {
      setUsers([]);
    }
  };

  const submit = () => {
    setErrors({});
    setFailure(null);

    const body: InventoryWriteBody = {
      name: form.name.trim(),
      brand: form.brand.trim(),
      category: form.category.trim(),
      status: form.status,
      purchase_date: form.purchase_date,
      common_usage: form.common_usage,
      notes: form.notes.trim(),
      users: form.common_usage ? [] : users,
    };
    if (kind === 'asset') {
      body.model = form.model.trim();
      body.serial = form.serial.trim();
      body.location = form.location.trim();
      body.warranty_end = form.warranty_end;
    } else {
      body.version = form.version.trim();
      body.license_expiry = form.license_expiry;
    }
    if (session.user.company === '') {
      body.company = form.company.trim();
    }
    const updatedAt = itemQuery.data?.updated_at;
    if (isEdit && updatedAt !== null && updatedAt !== undefined) {
      body.expected_updated_at = updatedAt;
    }

    const onError = (error: unknown) => {
      if (error instanceof PanelApiError && error.isValidation) {
        const mapped: Record<string, string> = {};
        for (const [field, code] of Object.entries(error.errors)) {
          mapped[field] = t(inventoryErrorKey(code));
        }
        setErrors(mapped);
        return;
      }
      setFailure(error instanceof Error ? error.message : String(error));
    };

    const onSuccess = () => {
      toast.success(isEdit ? t('ui_saved') : t('ui_inventory_created'));
      void navigate({ to: inventoryRoute(kind) as '/assets' });
    };

    if (isEdit && itemId !== null) {
      update.mutate({ id: itemId, body }, { onSuccess, onError });
      return;
    }
    create.mutate(body, { onSuccess, onError });
  };

  const onDelete = () => {
    if (itemId === null || !window.confirm(t('ui_delete_inventory_confirm'))) {
      return;
    }
    remove.mutate(itemId, {
      onSuccess: () => {
        toast.success(t('ui_inventory_deleted'));
        void navigate({ to: inventoryRoute(kind) as '/assets' });
      },
      onError: (error) => setFailure(error instanceof Error ? error.message : String(error)),
    });
  };

  const busy = create.isPending || update.isPending || remove.isPending;
  const canDelete = session.capabilities.permissions[`${kind}s.delete`] === true;
  const listPath = inventoryRoute(kind);

  if (isEdit && itemQuery.isError) {
    return (
      <Alert
        tone="danger"
        title={t('ui_inventory_not_found')}
        action={
          <Link to={listPath} className="text-sm text-[var(--color-accent)]">
            {t('back_to_list')}
          </Link>
        }
      />
    );
  }

  if (isEdit && itemQuery.isPending) {
    return (
      <div className="flex items-center gap-2 text-sm text-[var(--color-muted)]">
        <Spinner className="size-4" /> {t('ui_loading')}
      </div>
    );
  }


  return (
    <section className="mx-auto flex w-full max-w-3xl flex-col gap-3">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="m-0 text-xl font-semibold">
          {t(isEdit ? inventoryEditKey(kind) : inventoryNewKey(kind))}
        </h1>
        <Link to={listPath} className="text-sm text-[var(--color-accent)]">
          {t('back_to_list')}
        </Link>
      </header>

      {failure === null ? null : (
        <Alert tone="danger" title={t('save_failed')}>
          <p className="m-0">{failure}</p>
        </Alert>
      )}

      <div className="card grid gap-3 p-4 sm:grid-cols-2">
        <TextField
          id="inventory-name"
          label={t('col_item_name')}
          value={form.name}
          error={errors.name}
          className="sm:col-span-2"
          onChange={(value) => patch({ name: value })}
        />
        <TextField
          id="inventory-brand"
          label={t('col_item_brand')}
          value={form.brand}
          error={errors.brand}
          onChange={(value) => patch({ brand: value })}
        />
        <TextField
          id="inventory-category"
          label={t('col_item_category')}
          value={form.category}
          error={errors.category}
          onChange={(value) => patch({ category: value })}
        />

        {kind === 'asset' ? (
          <>
            <TextField
              id="inventory-model"
              label={t('col_item_model')}
              value={form.model}
              error={errors.model}
              onChange={(value) => patch({ model: value })}
            />
            <TextField
              id="inventory-serial"
              label={t('col_item_serial')}
              value={form.serial}
              error={errors.serial}
              onChange={(value) => patch({ serial: value })}
            />
            <TextField
              id="inventory-location"
              label={t('col_item_location')}
              value={form.location}
              error={errors.location}
              onChange={(value) => patch({ location: value })}
            />
          </>
        ) : (
          <TextField
            id="inventory-version"
            label={t('col_item_version')}
            value={form.version}
            error={errors.version}
            onChange={(value) => patch({ version: value })}
          />
        )}

        <Field label={t('col_status')} htmlFor="inventory-status">
          <Select
            id="inventory-status"
            value={form.status}
            onChange={(event) => patch({ status: event.target.value })}
          >
            {statuses.map((status) => (
              <option key={status.value} value={status.value}>
                {t(status.label_key)}
              </option>
            ))}
          </Select>
          {errors.status === undefined ? null : (
            <p className="m-0 text-xs text-[var(--color-danger)]">{errors.status}</p>
          )}
        </Field>

        <TextField
          id="inventory-purchase-date"
          type="date"
          label={t('col_purchase_date')}
          value={form.purchase_date}
          error={errors.purchase_date}
          onChange={(value) => patch({ purchase_date: value })}
        />

        {kind === 'asset' ? (
          <TextField
            id="inventory-warranty-end"
            type="date"
            label={t('col_warranty')}
            value={form.warranty_end}
            error={errors.warranty_end}
            onChange={(value) => patch({ warranty_end: value })}
          />
        ) : (
          <TextField
            id="inventory-license-expiry"
            type="date"
            label={t('col_item_license_expiry')}
            value={form.license_expiry}
            error={errors.license_expiry}
            onChange={(value) => patch({ license_expiry: value })}
          />
        )}

        {session.user.company === '' ? (
          <TextField
            id="inventory-company"
            label={t('col_company')}
            value={form.company}
            error={errors.company}
            onChange={(value) => patch({ company: value })}
          />
        ) : null}

        <Field label={t('col_notes')} htmlFor="inventory-notes" className="sm:col-span-2">
          <Textarea
            id="inventory-notes"
            value={form.notes}
            onChange={(event) => patch({ notes: event.target.value })}
          />
        </Field>
      </div>


      <div className="card flex flex-col gap-3 p-4">
        <div className="flex flex-col gap-1">
          <Checkbox
            label={t('ui_common_usage')}
            checked={form.common_usage}
            onChange={(event) => setCommonUsage(event.target.checked)}
          />
          <p className="m-0 text-xs text-[var(--color-muted)]">{t('ui_common_usage_hint')}</p>
        </div>

        <Field label={t('ui_assigned_users')} htmlFor="inventory-users">
          <UserMultiSelect
            company={form.company !== '' ? form.company : session.user.company}
            value={users}
            disabled={form.common_usage}
            onChange={setUsers}
          />
          <p className="m-0 text-xs text-[var(--color-muted)]">{t('ui_assigned_users_hint')}</p>
          {errors.users === undefined ? null : (
            <p className="m-0 text-xs text-[var(--color-danger)]">{errors.users}</p>
          )}
        </Field>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <Button variant="primary" disabled={busy} onClick={submit}>
          {busy ? <Spinner className="size-4" /> : null}
          {t(isEdit ? 'save_changes' : 'ui_create')}
        </Button>
        <Link to={listPath} className="text-sm text-[var(--color-accent)]">
          {t('cancel')}
        </Link>
        {isEdit && canDelete ? (
          <Button variant="ghost" className="ms-auto" disabled={busy} onClick={onDelete}>
            {t('delete')}
          </Button>
        ) : null}
      </div>
    </section>
  );
}

/** `/assets/new` · `/assets/$itemId/edit` */
export function AssetFormPage() {
  return <InventoryFormPage kind="asset" />;
}

/** `/systems/new` · `/systems/$itemId/edit` */
export function SystemFormPage() {
  return <InventoryFormPage kind="system" />;
}

