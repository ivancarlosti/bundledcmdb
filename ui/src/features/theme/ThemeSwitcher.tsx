import { useTranslation } from 'react-i18next';

import { Select } from '@/components/ui';
import { useCurrentSession } from '@/features/auth/SessionContext';
import { usePreferences } from '@/features/preferences/usePreferences';
import { applyTheme, isThemeMode, storeTheme } from '@/lib/theme';

const LABEL_KEYS: Record<string, string> = {
  system: 'theme_system',
  light: 'theme_light',
  dark: 'theme_dark',
};

/**
 * Theme selector. The attribute is applied synchronously (before the PATCH
 * round-trip) so switching feels instant, and the server value is written back
 * into the session cache — one source of truth per direction of travel.
 */
export function ThemeSwitcher() {
  const { t } = useTranslation();
  const session = useCurrentSession();
  const preferences = usePreferences();

  return (
    <Select
      data-testid="theme-switcher"
      aria-label={t('ui_theme')}
      value={session.theme}
      disabled={preferences.isPending}
      onChange={(event) => {
        const next = event.target.value;
        if (!isThemeMode(next)) {
          return;
        }
        applyTheme(next);
        storeTheme(next);
        preferences.mutate({ theme: next });
      }}
    >
      {['system', 'light', 'dark'].map((mode) => (
        <option key={mode} value={mode}>
          {t(LABEL_KEYS[mode] ?? mode)}
        </option>
      ))}
    </Select>
  );
}
