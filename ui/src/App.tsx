import { useEffect } from 'react';

import { applyTheme, type ThemeMode } from '@/lib/theme';
import { useSession } from '@/features/auth/useSession';

/**
 * Scaffold shell (phase 4 replaces it with the router + AppShell).
 *
 * It already proves the contract the whole migration depends on: the SPA boots
 * from `GET /panel-api/v1/session`, shows the login screen on `401`, and applies
 * the stored theme/locale direction before rendering any data.
 */
export function App() {
  const { data: session, isPending, error, refetch } = useSession();

  // Server preference wins over the pre-paint localStorage guess, and the locale
  // drives `lang`/`dir` (RTL for ar-SA).
  useEffect(() => {
    if (!session) {
      return;
    }
    applyTheme(session.theme as ThemeMode);
    document.documentElement.lang = session.locale;
    document.documentElement.dir = session.dir;
  }, [session]);

  if (isPending) {
    return (
      <main className="mx-auto max-w-3xl p-6">
        <p className="text-[var(--color-muted)]" role="status">
          Loading…
        </p>
      </main>
    );
  }

  if (error) {
    return (
      <main className="mx-auto max-w-3xl p-6">
        <div className="card p-4" role="alert">
          <h1 className="m-0 text-lg font-semibold">Panel API unavailable</h1>
          <p className="mt-2 text-sm text-[var(--color-muted)]">{error.message}</p>
          <button
            type="button"
            className="mt-3 rounded-[var(--radius-md)] bg-[var(--color-accent)] px-3 py-2 text-sm font-medium text-[var(--color-accent-contrast)]"
            onClick={() => void refetch()}
          >
            Retry
          </button>
        </div>
      </main>
    );
  }

  if (session === null) {
    return (
      <main className="mx-auto flex min-h-dvh max-w-md flex-col justify-center gap-4 p-6">
        <div className="card p-6">
          <h1 className="m-0 text-xl font-semibold">Bundled CMDB</h1>
          <p className="mt-2 text-sm text-[var(--color-muted)]">
            Sign in with your organisation account to continue.
          </p>
          <a
            className="mt-4 inline-block rounded-[var(--radius-md)] bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-[var(--color-accent-contrast)]"
            href="/auth/login?redirect=/app/"
          >
            Sign in
          </a>
        </div>
      </main>
    );
  }

  return (
    <main className="mx-auto max-w-3xl p-6">
      <header className="card flex flex-wrap items-center justify-between gap-3 p-4">
        <div>
          <h1 className="m-0 text-lg font-semibold">Bundled CMDB</h1>
          <p className="m-0 text-sm text-[var(--color-muted)]">
            {session.user.email} · {session.user.role}
            {session.user.company !== '' ? ` · ${session.user.company}` : ''}
          </p>
        </div>
        <span className="rounded-[var(--radius-sm)] bg-[var(--color-surface-3)] px-2 py-1 text-xs">
          theme: {session.theme} · {session.locale}
        </span>
      </header>
      <section className="card mt-4 p-4">
        <p className="m-0 text-sm text-[var(--color-muted)]">
          The device grid lands here in phase 5. Endpoints already available: <code>/panel-api/v1/meta</code>,{' '}
          <code>/panel-api/v1/devices</code>, <code>/panel-api/v1/devices/export</code>.
        </p>
      </section>
    </main>
  );
}
