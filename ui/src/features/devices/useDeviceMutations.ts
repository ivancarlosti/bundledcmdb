import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

import { PanelApiError } from '@/api/client';

import {
  bulkDevices,
  createDevice,
  deleteDevice,
  deleteDeviceFile,
  restoreDevice,
  updateDevice,
  uploadDeviceFile,
  type BulkOp,
  type DeviceDetail,
  type DeviceWriteBody,
} from './api';

/**
 * Write hooks for the device screens.
 *
 * Each one invalidates the grid (`['devices']`) and refreshes the cached detail
 * row, so navigating back to the list never shows the pre-edit values — the
 * "inline edit is silently discarded" regression from `2279453` was exactly a
 * cache/refresh problem.
 */

/**
 * Human-readable reason for a failed write.
 *
 * `PanelApiError` carries the server's message; anything else is a transport
 * failure (the XHR that never reached the bucket, a dropped connection). Those
 * used to fall back to an empty description, so the user saw "Could not upload
 * the file." with no clue — exactly when the detail matters most.
 */
function messageOf(error: unknown, fallback: string): string {
  if (error instanceof Error && error.message !== '') {
    return error.message;
  }
  return fallback;
}

export function useCreateDevice() {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: (body: DeviceWriteBody) => createDevice(body),
    onSuccess: (device: DeviceDetail) => {
      queryClient.setQueryData(['device', device.id], device);
      void queryClient.invalidateQueries({ queryKey: ['devices'] });
      toast.success(t('ui_created'));
    },
    onError: (error: unknown) => {
      toast.error(t('save_failed'), { description: messageOf(error, '') });
    },
  });
}

export function useUpdateDevice(id: number | null) {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: (body: DeviceWriteBody) => updateDevice(id as number, body),
    onSuccess: (device: DeviceDetail) => {
      queryClient.setQueryData(['device', device.id], device);
      void queryClient.invalidateQueries({ queryKey: ['devices'] });
      toast.success(t('ui_saved'));
    },
    onError: (error: unknown) => {
      // A stale record is explained in the form, where the user can reload.
      if (error instanceof PanelApiError && error.isStale) {
        return;
      }
      toast.error(t('save_failed'), { description: messageOf(error, '') });
    },
  });
}

/**
 * Inline edit from the grid (Status / BYOD).
 *
 * Sends the row's `updated_at` as `expected_updated_at`: if somebody else saved
 * the row in the meantime the server answers `409 stale_record`, the list is
 * refreshed and the user sees *why* the change did not stick instead of the old
 * value silently coming back on the next reload.
 */
export function useInlineDeviceUpdate() {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: ({ id, body }: { id: number; body: DeviceWriteBody }) => updateDevice(id, body),
    onSuccess: (device: DeviceDetail) => {
      queryClient.setQueryData(['device', device.id], device);
      void queryClient.invalidateQueries({ queryKey: ['devices'] });
      toast.success(t('ui_saved'));
    },
    onError: (error: unknown) => {
      if (error instanceof PanelApiError && error.isStale) {
        toast.error(t('ui_stale_record'));
        void queryClient.invalidateQueries({ queryKey: ['devices'] });
        return;
      }
      toast.error(t('save_failed'), { description: messageOf(error, '') });
    },
  });
}

/**
 * Bulk action from the grid's selection toolbar.
 *
 * The response carries per-row results, so a partially failing selection still
 * tells the user what actually happened ("2 updated, 1 skipped") and the reason
 * for the first skip — the alternative is a silent no-op, which is exactly the
 * class of bug the legacy grid had.
 */
export function useBulkDeviceAction() {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: ({
      op,
      ids,
      value,
      reason,
    }: {
      op: BulkOp;
      ids: number[];
      value?: string | boolean;
      reason?: string | null;
    }) => bulkDevices(op, ids, { value, reason }),
    onSuccess: (result) => {
      void queryClient.invalidateQueries({ queryKey: ['devices'] });
      for (const id of result.updated) {
        void queryClient.invalidateQueries({ queryKey: ['device', id] });
      }

      if (result.skipped.length === 0) {
        toast.success(t('ui_bulk_done', { done: result.updated.length }));
        return;
      }
      toast.warning(t('ui_bulk_partial', { done: result.updated.length, skipped: result.skipped.length }), {
        description: result.skipped[0]?.message ?? '',
      });
    },
    onError: (error: unknown) => {
      toast.error(t('save_failed'), { description: messageOf(error, '') });
    },
  });
}

export function useDeleteDevice() {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: (options: { id: number; hard?: boolean; reason?: string | null; duplicateOf?: number | null }) =>
      deleteDevice(options.id, { hard: options.hard, reason: options.reason, duplicateOf: options.duplicateOf }),
    onSuccess: (result) => {
      queryClient.removeQueries({ queryKey: ['device', result.id] });
      void queryClient.invalidateQueries({ queryKey: ['devices'] });
      toast.success(t('ui_deleted'));
    },
    onError: (error: unknown) => {
      toast.error(t('delete_failed'), { description: messageOf(error, '') });
    },
  });
}

export function useRestoreDevice() {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: (id: number) => restoreDevice(id),
    onSuccess: ({ device }) => {
      queryClient.setQueryData(['device', device.id], device);
      void queryClient.invalidateQueries({ queryKey: ['devices'] });
      toast.success(t('ui_restored'));
    },
    onError: (error: unknown) => {
      toast.error(t('save_failed'), { description: messageOf(error, '') });
    },
  });
}

export function useUploadDeviceFile(id: number | null) {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: (input: { file: File; onProgress?: (fraction: number) => void }) =>
      uploadDeviceFile(id as number, input.file, input.onProgress),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['device', id] });
      void queryClient.invalidateQueries({ queryKey: ['devices'] });
      toast.success(t('upload_btn'));
    },
    onError: (error: unknown) => {
      toast.error(t('upload_failed'), { description: messageOf(error, '') });
    },
  });
}

export function useDeleteDeviceFile(id: number | null) {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: (fileId: number) => deleteDeviceFile(id as number, fileId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['device', id] });
      void queryClient.invalidateQueries({ queryKey: ['devices'] });
      toast.success(t('delete'));
    },
    onError: (error: unknown) => {
      toast.error(t('delete_failed'), { description: messageOf(error, '') });
    },
  });
}
