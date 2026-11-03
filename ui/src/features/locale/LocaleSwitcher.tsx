import { useTranslation } from 'react-i18next';

import { Select } from '@/components/ui';
import { useCurrentSession } from '@/features/auth/SessionContext';
import { usePreferences } from '@/features/preferences/usePreferences';

/**
 * Language selector. Changing it PATCHes the preference; `SessionGate` then
 * loads the new catalogue and `dir`/`lang` on `<html>` follow the server's
 * answer (so RTL switches in the same step as the strings).
 */
export function LocaleSwitcher() {
  const { t } = useTranslation();
  const session = useCurrentSession();
  const preferences = usePreferences();

  return (
    <Select
      data-testid="locale-switcher"
      aria-label={t('language')}
      value={session.locale}
      disabled={preferences.isPending}
      onChange={(event) => preferences.mutate({ locale: event.target.value })}
    >
      {session.locales.map((locale) => (
        <option key={locale.code} value={locale.code}>
          {locale.native}
        </option>
      ))}
    </Select>
  );
}
