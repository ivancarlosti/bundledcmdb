import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';

import { createView, deleteView, fetchViews, type SavedView, type SavedViewState } from './api';

export const viewsQueryKey = ['views'] as const;

/** The signed-in user's saved grid presets. */
export function useViews() {
  return useQuery({
    queryKey: viewsQueryKey,
    queryFn: fetchViews,
    staleTime: 60_000,
  });
}

/**
 * Create or overwrite a view.
 *
 * The API answers `201` for both (the name is unique per user), so the list is
 * simply invalidated instead of patched by hand.
 */
export function useSaveView() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: { name: string; state: SavedViewState }) => createView(input.name, input.state),
    onSuccess: (view: SavedView) => {
      void queryClient.invalidateQueries({ queryKey: viewsQueryKey });
      toast.success(`${view.name}`);
    },
    onError: (error: Error) => toast.error(error.message),
  });
}

export function useDeleteView() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteView(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: viewsQueryKey });
    },
    onError: (error: Error) => toast.error(error.message),
  });
}
