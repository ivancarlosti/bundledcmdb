import { useQuery } from '@tanstack/react-query';
import { useEffect, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

import { Alert, Button, Spinner } from '@/components/ui';
import { LoginScreen } from '@/features/auth/LoginScreen';
import { SessionProvider } from '@/features/auth/SessionContext';
import { useSession } from '@/features/auth/useSession';
import { loadLocale } from '@/i18n';
import { applyTheme } from '@/lib/theme';

/** English labels used before any catalogue is available (boot/failure only). */
const BOOT_LABELS: Record<string, string> = {
  ui_loading: 'Loading…',
  ui_api_unavailable: 'Panel API unavailable',
  ui_retry: 'Try again',
};

function BootScreen() {
  const { t } = useTranslation();
  return (
    <main className="mx-auto flex min-h-dvh items-center justify-center gap-2 p-6 text-sm text-[var(--color-muted)]">
      <Spinner />
      <span role="status">{t('ui_loading', { defaultValue: BOOT_LABELS.ui_loading })}</span>
    </main>
  );
}

/**
 * Session bootstrap: pending -> error -> login -> app.
 *
 * It also owns the two global side effects that must happen before any screen
 * is painted: applying the theme and loading the translation catalogue (which
 * sets `lang`/`dir` on <html> at the same time).
 */
export function SessionGate({ children }: { children: ReactNode }) {
  const sessionQuery = useSession();
  const session = sessionQuery.data ?? null;
  const locale = session?.locale ?? 'en-US';

  const catalogue = useQuery({
    queryKey: ['i18n', locale],
    queryFn: () => loadLocale(locale),
    enabled: session !== null,
    staleTime: Infinity,
    gcTime: Infinity,
    retry: 1,
  });

  // Stay mounted once translations landed: a language switch then swaps strings
  // in place instead of flashing the boot screen.
  const [translated, setTranslated] = useState(false);
  useEffect(() => {
    if (catalogue.isSuccess) {
      setTranslated(true);
    }
  }, [catalogue.isSuccess]);

  useEffect(() => {
    if (session === null) {
      return;
    }
    applyTheme(session.theme);
    document.documentElement.lang = session.locale;
    document.documentElement.dir = session.dir;
  }, [session]);

  // `staleTime: Infinity` keeps a downloaded catalogue, which means switching
  // *back* to a locale does not re-run the query — and the language would stay
  // on the previous one. `loadLocale` is idempotent (no request once the
  // catalogue is cached), so the activation is repeated here.
  useEffect(() => {
    if (session === null || !catalogue.isSuccess) {
      return;
    }
    void loadLocale(session.locale);
  }, [session, catalogue.isSuccess]);

  if (sessionQuery.isPending) {
    return <BootScreen />;
  }

  if (sessionQuery.isError) {
    return (
      <main className="mx-auto flex min-h-dvh w-full max-w-lg flex-col justify-center p-6">
        <Alert
          title={BOOT_LABELS.ui_api_unavailable}
          action={<Button onClick={() => void sessionQuery.refetch()}>{BOOT_LABELS.ui_retry}</Button>}
        >
          <p className="m-0">{sessionQuery.error instanceof Error ? sessionQuery.error.message : ''}</p>
        </Alert>
      </main>
    );
  }

  if (session === null) {
    return <LoginScreen />;
  }

  if (!translated) {
    return <BootScreen />;
  }

  return <SessionProvider session={session}>{children}</SessionProvider>;
}
