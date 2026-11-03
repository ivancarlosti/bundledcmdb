import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

import { apiRequest, PanelApiError, setCsrfToken } from '@/api/client';
import type { Session } from '@/api/types';
import { sessionQueryKey } from '@/features/auth/useSession';

/** Body accepted by `PATCH /panel-api/v1/session/preferences`. */
export type PreferencesPatch = {
  locale?: string;
  theme?: string;
  per_page?: number;
  grid_state?: Record<string, unknown> | null;
};

async function savePreferences(patch: PreferencesPatch): Promise<Session> {
  const session = await apiRequest<Session>('/session/preferences', { method: 'PATCH', body: patch });
  setCsrfToken(session.csrfToken);
  return session;
}

/** Apply a patch to the cached session (optimistic update + rollback source). */
function applyPatch(session: Session, patch: PreferencesPatch): Session {
  return {
    ...session,
    ...(patch.locale === undefined ? {} : { locale: patch.locale }),
    ...(patch.theme === undefined ? {} : { theme: patch.theme as Session['theme'] }),
    ...(patch.per_page === undefined ? {} : { perPage: patch.per_page }),
    ...(patch.grid_state === undefined ? {} : { gridState: patch.grid_state as Session['gridState'] }),
  };
}

/**
 * Persist one preference and refresh the cached session with the server's view
 * of it (which is what the switchers render, so the UI cannot drift).
 *
 * The cache is updated optimistically so a switch or a column toggle reacts
 * immediately instead of waiting for the round-trip, and rolled back if the
 * request fails.
 */
export function usePreferences() {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: savePreferences,
    onMutate: async (patch: PreferencesPatch) => {
      await queryClient.cancelQueries({ queryKey: sessionQueryKey });
      const previous = queryClient.getQueryData<Session | null>(sessionQueryKey);
      if (previous !== null && previous !== undefined) {
        queryClient.setQueryData<Session>(sessionQueryKey, applyPatch(previous, patch));
      }
      return { previous };
    },
    onSuccess: (session) => {
      queryClient.setQueryData(sessionQueryKey, session);
    },
    onError: (error: unknown, _patch, context) => {
      if (context?.previous !== undefined) {
        queryClient.setQueryData(sessionQueryKey, context.previous);
      }
      const message = error instanceof PanelApiError ? error.message : t('save_failed');
      toast.error(t('save_failed'), { description: message });
    },
  });
}
