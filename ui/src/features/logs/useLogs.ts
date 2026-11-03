/**
 * Audit log queries.
 *
 * The key is the whole filter object, so paging back to a page already seen is
 * instant and `keepPreviousData` keeps the table from collapsing while the next
 * page loads. A purge invalidates the family, because every page can change.
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

import { fetchLogs, purgeLogs, type LogFilters } from './api';

export function useLogs(filters: LogFilters) {
  return useQuery({
    queryKey: ['logs', filters],
    queryFn: () => fetchLogs(filters),
    placeholderData: keepPreviousData,
    staleTime: 15_000,
  });
}

export function usePurgeLogs() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ before, dryRun }: { before: string; dryRun: boolean }) => purgeLogs(before, dryRun),
    onSuccess: (result) => {
      // A dry run is the confirmation step; only the real one is news.
      if (result.dry_run) {
        return;
      }
      toast.success(t('logs_purge_done', { count: result.deleted }));
      void queryClient.invalidateQueries({ queryKey: ['logs'] });
    },
    onError: () => {
      toast.error(t('delete_failed'));
    },
  });
}
