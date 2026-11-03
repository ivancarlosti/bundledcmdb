import { createContext, useContext, type ReactNode } from 'react';

import type { Session } from '@/api/types';

/**
 * The session is fetched once by `SessionGate`. Everything below the shell reads
 * it from here instead of re-querying, so capabilities, locale list and theme
 * cannot disagree with what the API returned.
 */
const SessionContext = createContext<Session | null>(null);

export function SessionProvider({ session, children }: { session: Session; children: ReactNode }) {
  return <SessionContext.Provider value={session}>{children}</SessionContext.Provider>;
}

export function useCurrentSession(): Session {
  const session = useContext(SessionContext);
  if (session === null) {
    throw new Error('useCurrentSession() must be used inside <SessionProvider>.');
  }
  return session;
}
