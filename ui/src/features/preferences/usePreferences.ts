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
  grid_state?: Record<string, unknown>;
};

async function savePreferences(patch: PreferencesPatch): Promise<Session> {
  const session = await apiRequest<Session>('/session/preferences', { method: 'PATCH', body: patch });
  setCsrfToken(session.csrfToken);
  return session;
}

/**
 * Persist one preference and refresh the cached session with the server's view
 * of it (which is what the switchers render, so the UI cannot drift).
 */
export function usePreferences() {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: savePreferences,
    onSuccess: (session) => {
      queryClient.setQueryData(sessionQueryKey, session);
    },
    onError: (error: unknown) => {
      const message = error instanceof PanelApiError ? error.message : t('save_failed');
      toast.error(t('save_failed'), { description: message });
    },
  });
}
