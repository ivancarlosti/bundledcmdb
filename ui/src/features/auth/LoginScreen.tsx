import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';

import { Alert, Button, Field, Input } from '@/components/ui';

const RETURN_PATH = encodeURIComponent('/app/');

/**
 * Sign-in failures reported by `public/index.php` (the legacy `/index.php?code=`
 * SSO callback) land here as `?auth_error=<code>`. The SPA owns every error
 * state, so the callback redirects instead of rendering a PHP page.
 */
const AUTH_ERRORS: Record<string, { key: string; fallback: string }> = {
  not_authorized: {
    key: 'login_err_not_authorized',
    fallback: 'Access denied: your account is not on the authorised list.',
  },
  userinfo_failed: {
    key: 'login_err_userinfo_failed',
    fallback: 'Signed in, but the identity provider did not return your profile.',
  },
  token_failed: {
    key: 'login_err_token_failed',
    fallback: 'The identity provider rejected the sign-in attempt.',
  },
  server_error: {
    key: 'login_err_server_error',
    fallback: 'The panel could not reach its database. Try again in a moment.',
  },
};

/**
 * Sign-in screen.
 *
 * The real flow is a full-page navigation to `/auth/login`, which starts the
 * Keycloak authorization code flow and lands back on `/app/` with a session
 * cookie — the SPA never sees a token. `import.meta.env.DEV` additionally exposes
 * the server's dev login (it is disabled outside development by `auth.php`).
 */
export function LoginScreen() {
  const { t } = useTranslation();
  const [email, setEmail] = useState('');
  const showDevLogin = import.meta.env.DEV;
  const authError = AUTH_ERRORS[new URLSearchParams(window.location.search).get('auth_error') ?? ''];

  const onDevLogin = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    window.location.assign(`/auth/dev-login?email=${encodeURIComponent(email)}&redirect=${RETURN_PATH}`);
  };

  return (
    <main className="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center gap-4 p-6">
      {authError ? (
        <Alert tone="danger" title={t('login_err_title', { defaultValue: 'Sign-in failed' })}>
          <p className="m-0">{t(authError.key, { defaultValue: authError.fallback })}</p>
        </Alert>
      ) : null}

      <div className="card p-6">
        <h1 className="m-0 text-xl font-semibold">{t('panel_title', { defaultValue: 'CMDB' })}</h1>
        <p className="mt-2 text-sm text-[var(--color-muted)]">
          {t('login_prompt', { defaultValue: 'Please sign in using your company account.' })}
        </p>
        <a
          className="mt-4 inline-flex items-center justify-center rounded-[var(--radius-md)] bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-[var(--color-accent-contrast)]"
          href={`/auth/login?redirect=${RETURN_PATH}`}
        >
          {t('sso_btn', { defaultValue: 'Sign in with SSO' })}
        </a>

        {showDevLogin ? (
          <form className="mt-6 flex flex-col gap-2 border-t border-[var(--color-border)] pt-4" onSubmit={onDevLogin}>
            <Field label={t('ui_dev_login', { defaultValue: 'Development sign-in' })} htmlFor="dev-login-email">
              <Input
                id="dev-login-email"
                type="email"
                autoComplete="email"
                required
                value={email}
                placeholder="user@example.com"
                onChange={(event) => setEmail(event.target.value)}
              />
            </Field>
            <Button type="submit" variant="secondary">
              {t('ui_dev_login', { defaultValue: 'Development sign-in' })}
            </Button>
          </form>
        ) : null}
      </div>
    </main>
  );
}
