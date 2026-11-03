/**
 * React Query bindings for the settings screens.
 *
 * Every write returns the refreshed list, so a mutation seeds the cache with the
 * server's answer instead of triggering a second GET — the same rule the device
 * screens follow. Validation failures (`422` + `errors` per key) are left to the
 * form: it renders them next to the input, so the toast would only be noise.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

import { PanelApiError } from '@/api/client';
import { sessionQueryKey } from '@/features/auth/useSession';
import { metaQueryKey } from '@/features/devices/useDevices';

import {
  createApiKey,
  createField,
  createSource,
  deleteApiKey,
  deleteField,
  deleteSource,
  fetchApiKeys,
  fetchFields,
  fetchMaintenance,
  fetchPermissions,
  fetchSettings,
  fetchSources,
  fetchUsers,
  moveSource,
  reorderFields,
  rotateApiKey,
  runMigrations,
  savePermissions,
  saveSettings,
  updateApiKey,
  updateField,
  updateSource,
  updateUserRole,
  type ApiKeyCreated,
  type ApiKeyInput,
  type FieldsPayload,
  type FieldInput,
  type MaintenancePayload,
  type MigrationRun,
  type PermissionsInput,
  type PermissionsPayload,
  type SettingsPatchBody,
  type SettingsPayload,
  type SourceInput,
  type SourceMoveResponse,
  type SourcesPayload,
  type UsersPayload,
} from './api';

export const settingsQueryKey = ['settings', 'core'] as const;
export const settingsSourcesQueryKey = ['settings', 'sources'] as const;
export const settingsFieldsQueryKey = ['settings', 'fields'] as const;
export const settingsKeysQueryKey = ['settings', 'api-keys'] as const;
export const settingsUsersQueryKey = ['settings', 'users'] as const;
export const settingsMaintenanceQueryKey = ['settings', 'maintenance'] as const;
export const settingsPermissionsQueryKey = ['settings', 'permissions'] as const;

function messageOf(error: unknown, fallback: string): string {
  return error instanceof PanelApiError ? error.message : fallback;
}

/** 422 with per-key `errors` is rendered by the form, not by a toast. */
function isFieldLevel(error: unknown): boolean {
  return error instanceof PanelApiError && error.isValidation;
}

export function useSettings() {
  return useQuery({
    queryKey: settingsQueryKey,
    queryFn: fetchSettings,
    staleTime: 30_000,
  });
}

export function useSettingsSources() {
  return useQuery({
    queryKey: settingsSourcesQueryKey,
    queryFn: fetchSources,
    staleTime: 30_000,
  });
}

export function useSettingsFields() {
  return useQuery({
    queryKey: settingsFieldsQueryKey,
    queryFn: fetchFields,
    staleTime: 30_000,
  });
}

export function useApiKeys() {
  return useQuery({
    queryKey: settingsKeysQueryKey,
    queryFn: fetchApiKeys,
    staleTime: 30_000,
  });
}

export function useSettingsUsers(enabled: boolean) {
  return useQuery({
    queryKey: settingsUsersQueryKey,
    queryFn: fetchUsers,
    enabled,
    staleTime: 30_000,
  });
}

export function usePermissions(enabled: boolean) {
  return useQuery({
    queryKey: settingsPermissionsQueryKey,
    queryFn: fetchPermissions,
    enabled,
    staleTime: 30_000,
  });
}

export function useMaintenance() {
  return useQuery({
    queryKey: settingsMaintenanceQueryKey,
    queryFn: fetchMaintenance,
    staleTime: 15_000,
  });
}

export function useSaveSettings() {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: (body: SettingsPatchBody) => saveSettings(body),
    onSuccess: ({ payload, saved }: { payload: SettingsPayload; saved: string[] }) => {
      queryClient.setQueryData(settingsQueryKey, payload);
      // `assets.*` and `ui.spa_enabled` change what `/meta` publishes.
      void queryClient.invalidateQueries({ queryKey: metaQueryKey });
      toast.success(t('set_saved'), {
        description: saved.length === 0 ? undefined : saved.join(', '),
      });
    },
    onError: (error: unknown) => {
      if (isFieldLevel(error)) {
        return;
      }
      toast.error(t('set_error'), { description: messageOf(error, '') });
    },
  });
}

/** Create / update / delete for the provenance list. */
export function useSourceMutations() {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  const seed = (payload: SourcesPayload) => {
    queryClient.setQueryData(settingsSourcesQueryKey, payload);
    // The settings form and the API-key drawer both read the source list.
    void queryClient.invalidateQueries({ queryKey: settingsQueryKey });
    void queryClient.invalidateQueries({ queryKey: settingsKeysQueryKey });
    void queryClient.invalidateQueries({ queryKey: metaQueryKey });
  };

  const onError = (error: unknown) => {
    if (isFieldLevel(error)) {
      return;
    }
    toast.error(t('save_failed'), { description: messageOf(error, '') });
  };

  const create = useMutation({
    mutationFn: (input: SourceInput) => createSource(input),
    onSuccess: (payload) => {
      seed(payload);
      toast.success(t('set_saved'));
    },
    onError,
  });

  const update = useMutation({
    mutationFn: ({ slug, input }: { slug: string; input: Omit<SourceInput, 'slug'> }) => updateSource(slug, input),
    onSuccess: (payload) => {
      seed(payload);
      toast.success(t('set_saved'));
    },
    onError,
  });

  const remove = useMutation({
    mutationFn: (slug: string) => deleteSource(slug),
    onSuccess: (payload) => {
      seed(payload);
      toast.success(t('set_delete'));
    },
    onError,
  });

  const move = useMutation({
    mutationFn: ({ from, to }: { from: string; to: string }): Promise<SourceMoveResponse> => moveSource(from, to),
    onSuccess: ({ payload, result }) => {
      seed(payload);
      toast.success(t('set_source_moved'), {
        description: t('set_source_moved_detail', {
          assets: result.assets,
          keys: result.api_keys,
        }),
      });
    },
    onError,
  });

  return { create, update, remove, move };
}

/** Load and save the role matrix; a save also refreshes the session flags. */
export function usePermissionMutations() {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: (input: PermissionsInput): Promise<PermissionsPayload> => savePermissions(input),
    onSuccess: (payload) => {
      queryClient.setQueryData(settingsPermissionsQueryKey, payload);
      // The current user's own flags may have changed, and the sidebar/tabs read
      // them from the session payload.
      void queryClient.invalidateQueries({ queryKey: sessionQueryKey });
      void queryClient.invalidateQueries({ queryKey: metaQueryKey });
      // Custom-field rows share `editable_roles` with the Fields tab.
      void queryClient.invalidateQueries({ queryKey: settingsFieldsQueryKey });
      toast.success(t('set_saved'));
    },
    onError: (error: unknown) => {
      if (isFieldLevel(error)) {
        return;
      }
      toast.error(t('set_error'), { description: messageOf(error, '') });
    },
  });
}

/** Create / update / delete / reorder for the custom-field definitions. */
export function useFieldMutations() {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  const seed = (payload: FieldsPayload) => {
    queryClient.setQueryData(settingsFieldsQueryKey, payload);
    // `/meta` publishes the field list the device form renders.
    void queryClient.invalidateQueries({ queryKey: metaQueryKey });
  };

  const onError = (error: unknown) => {
    if (isFieldLevel(error)) {
      return;
    }
    toast.error(t('save_failed'), { description: messageOf(error, '') });
  };

  const create = useMutation({
    mutationFn: (input: FieldInput) => createField(input),
    onSuccess: (payload) => {
      seed(payload);
      toast.success(t('set_saved'));
    },
    onError,
  });

  const update = useMutation({
    mutationFn: ({ id, input }: { id: number; input: FieldInput }) => updateField(id, input),
    onSuccess: (payload) => {
      seed(payload);
      toast.success(t('set_saved'));
    },
    onError,
  });

  const remove = useMutation({
    mutationFn: (id: number) => deleteField(id),
    onSuccess: (payload) => {
      seed(payload);
      toast.success(t('set_delete'));
    },
    onError,
  });

  const reorder = useMutation({
    mutationFn: (ids: number[]) => reorderFields(ids),
    onSuccess: (payload) => {
      seed(payload);
    },
    onError,
  });

  return { create, update, remove, reorder };
}

/** API keys: create, edit scopes, rotate the token, delete. */
export function useApiKeyMutations() {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  const onError = (error: unknown) => {
    if (isFieldLevel(error)) {
      return;
    }
    toast.error(t('save_failed'), { description: messageOf(error, '') });
  };

  const create = useMutation({
    // A successful create returns the token exactly once, so it stays in the
    // mutation result for the "copy it now" panel instead of a toast.
    mutationFn: (input: ApiKeyInput): Promise<ApiKeyCreated> => createApiKey(input),
    onSuccess: ({ payload }) => {
      queryClient.setQueryData(settingsKeysQueryKey, payload);
      toast.success(t('set_saved'));
    },
    onError,
  });

  const update = useMutation({
    mutationFn: ({ id, input }: { id: number; input: ApiKeyInput }) => updateApiKey(id, input),
    onSuccess: (payload) => {
      queryClient.setQueryData(settingsKeysQueryKey, payload);
      toast.success(t('set_saved'));
    },
    onError,
  });

  const rotate = useMutation({
    mutationFn: (id: number): Promise<ApiKeyCreated> => rotateApiKey(id),
    onSuccess: ({ payload }) => {
      queryClient.setQueryData(settingsKeysQueryKey, payload);
      toast.success(t('set_key_rotate'));
    },
    onError,
  });

  const remove = useMutation({
    mutationFn: (id: number) => deleteApiKey(id),
    onSuccess: (payload) => {
      queryClient.setQueryData(settingsKeysQueryKey, payload);
      toast.success(t('set_key_delete'));
    },
    onError,
  });

  return { create, update, rotate, remove };
}

/** Superadmin role changes (the server refuses a self-demotion with 409). */
export function useUserMutations() {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: ({ email, role }: { email: string; role: string }): Promise<UsersPayload> =>
      updateUserRole(email, role),
    onSuccess: (payload) => {
      queryClient.setQueryData(settingsUsersQueryKey, payload);
      toast.success(t('set_saved'));
    },
    onError: (error: unknown) => {
      if (isFieldLevel(error)) {
        return;
      }
      toast.error(t('set_error'), { description: messageOf(error, '') });
    },
  });
}

/** `POST /settings/maintenance/migrate`; the dry run is the default action. */
export function useMigration() {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: (dryRun: boolean): Promise<{ payload: MaintenancePayload; run: MigrationRun }> => runMigrations(dryRun),
    onSuccess: ({ payload, run }) => {
      queryClient.setQueryData(settingsMaintenanceQueryKey, payload);
      toast.success(t('set_maint_applied'), {
        description: `${run.applied.length} / ${run.applied.length + run.skipped.length}`,
      });
    },
    onError: (error: unknown) => {
      toast.error(t('set_maint_run'), { description: messageOf(error, '') });
    },
  });
}
