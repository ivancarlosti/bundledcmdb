import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

import { PanelApiError } from '@/api/client';

import {
  createDevice,
  deleteDevice,
  deleteDeviceFile,
  restoreDevice,
  updateDevice,
  uploadDeviceFile,
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

function messageOf(error: unknown, fallback: string): string {
  return error instanceof PanelApiError ? error.message : fallback;
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

export function useDeleteDevice() {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: (options: { id: number; hard?: boolean }) => deleteDevice(options.id, options.hard ?? false),
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
    mutationFn: (file: File) => uploadDeviceFile(id as number, file),
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
