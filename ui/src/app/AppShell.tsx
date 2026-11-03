import { useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Toaster, toast } from 'sonner';

import { apiRequest } from '@/api/client';
import { Button } from '@/components/ui';
import { useCurrentSession } from '@/features/auth/SessionContext';
import { sessionQueryKey } from '@/features/auth/useSession';
import { LocaleSwitcher } from '@/features/locale/LocaleSwitcher';
import { ThemeSwitcher } from '@/features/theme/ThemeSwitcher';

const NAV_LINK = 'rounded-[var(--radius-md)] px-2 py-1 hover:bg-[var(--color-surface-2)]';

/**
 * Application shell: brand, navigation, theme/locale switchers and sign-out.
 *
 * The header is sticky but the page content is in normal flow, so the toolbar of
 * a screen can never end up underneath it.
 */
export function AppShell({ children }: { children: ReactNode }) {
  const { t } = useTranslation();
  const session = useCurrentSession();
  const queryClient = useQueryClient();
  const [signingOut, setSigningOut] = useState(false);

  const signOut = async () => {
    setSigningOut(true);
    try {
      const data = await apiRequest<{ logoutUrl: string }>('/session/logout', {
        method: 'POST',
        body: {},
      });
      queryClient.setQueryData(sessionQueryKey, null);
      window.location.assign(data.logoutUrl !== '' ? data.logoutUrl : '/app/');
    } catch {
      toast.error(t('save_failed'));
      setSigningOut(false);
    }
  };

  return (
    <div className="min-h-dvh">
      <header className="sticky top-0 z-20 border-b border-[var(--color-border)] bg-[var(--color-surface)]">
        <div className="mx-auto flex w-full max-w-[110rem] flex-wrap items-center gap-3 px-4 py-2">
          <Link to="/devices" className="text-base font-semibold">
            {t('panel_title')}
          </Link>
          <nav aria-label={t('panel_title')} className="flex items-center gap-1 text-sm">
            <Link
              to="/devices"
              className={NAV_LINK}
              activeProps={{ className: `${NAV_LINK} bg-[var(--color-surface-3)]` }}
            >
              {t('ui_devices')}
            </Link>
            {session.capabilities.can_admin ? (
              <Link
                to="/settings"
                className={NAV_LINK}
                activeProps={{ className: `${NAV_LINK} bg-[var(--color-surface-3)]` }}
              >
                {t('set_title')}
              </Link>
            ) : null}
          </nav>
          <div className="ms-auto flex flex-wrap items-center gap-2">
            <span className="hidden text-xs text-[var(--color-muted)] sm:inline">
              {session.user.email} · {t(`role_${session.capabilities.role}`)}
            </span>
            <ThemeSwitcher />
            <LocaleSwitcher />
            <Button variant="ghost" disabled={signingOut} onClick={() => void signOut()}>
              {t('logout')}
            </Button>
          </div>
        </div>
      </header>

      <main className="mx-auto w-full max-w-[110rem] p-4">{children}</main>

      <Toaster position="top-center" richColors closeButton />
    </div>
  );
}
