/**
 * Collect-data queries.
 *
 * One cache entry (`['collect']`) because the whole payload describes the caller
 * and their single active link: issuing or revoking replaces it wholesale, so a
 * surgical cache edit would only risk showing a stale expiry.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

import { createCollectFile, fetchCollect, revokeCollectFile, type CollectFormat } from './api';

export function useCollect() {
  return useQuery({
    queryKey: ['collect'],
    queryFn: fetchCollect,
    staleTime: 15_000,
  });
}

/** Save plain text as a download without involving the server again. */
export function saveTextFile(fileName: string, content: string): void {
  const blob = new Blob([content], { type: 'application/octet-stream' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = fileName;
  link.rel = 'noopener';
  document.body.appendChild(link);
  link.click();
  link.remove();
  // Revoking immediately can cancel the download in some browsers.
  window.setTimeout(() => URL.revokeObjectURL(url), 10_000);
}

export function useCreateCollectFile() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (format: CollectFormat) => createCollectFile(format),
    onSuccess: (result) => {
      saveTextFile(result.file.name, result.file.content);
      toast.success(t('collect_download_started'));
      void queryClient.invalidateQueries({ queryKey: ['collect'] });
    },
    onError: (error: Error) => {
      // `collect_disabled` and `company_unassigned` are stated by the server; show
      // its message instead of a generic failure when there is one.
      toast.error(error.message || t('collect_generate_failed'));
    },
  });
}

export function useRevokeCollectFile() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => revokeCollectFile(),
    onSuccess: () => {
      toast.success(t('collect_revoked'));
      void queryClient.invalidateQueries({ queryKey: ['collect'] });
    },
    onError: () => {
      toast.error(t('save_failed'));
    },
  });
}
