import { useQuery } from '@tanstack/react-query';

import { apiRequest, PanelApiError, setCsrfToken } from '@/api/client';
import type { Session } from '@/api/types';

export const sessionQueryKey = ['session'] as const;

/** `GET /session`; `null` means "not signed in" rather than an error. */
export async function fetchSession(): Promise<Session | null> {
  try {
    const session = await apiRequest<Session>('/session');
    setCsrfToken(session.csrfToken);
    return session;
  } catch (error) {
    if (error instanceof PanelApiError && error.isUnauthorized) {
      setCsrfToken(null);
      return null;
    }
    throw error;
  }
}

/**
 * The session query. `data === null` renders the login screen; loading and
 * transport errors are surfaced separately so an outage is not mistaken for an
 * anonymous visitor.
 */
export function useSession() {
  return useQuery({
    queryKey: sessionQueryKey,
    queryFn: fetchSession,
    staleTime: 60_000,
    retry: (attempt, error) => {
      if (error instanceof PanelApiError && error.isUnauthorized) {
        return false;
      }
      return attempt < 2;
    },
  });
}
