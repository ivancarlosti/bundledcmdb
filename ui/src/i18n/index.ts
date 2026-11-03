/**
 * i18next bootstrap.
 *
 * PHP stays the source of truth for translations (`public/lang/*.php`); the SPA
 * fetches the merged catalogue from `GET /panel-api/v1/i18n` and registers it as
 * a resource bundle. Nothing is bundled into the JS, so adding a language keeps
 * being a one-file change on the server side and `bin/check-i18n.php` keeps
 * guarding coverage.
 */

import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';

import { apiRequest, buildQuery } from '@/api/client';

export type I18nPayload = {
  locale: string;
  dir: 'ltr' | 'rtl';
  messages: Record<string, string>;
};

export const FALLBACK_LOCALE = 'en-US';

void i18n.use(initReactI18next).init({
  lng: FALLBACK_LOCALE,
  fallbackLng: FALLBACK_LOCALE,
  defaultNS: 'translation',
  ns: ['translation'],
  resources: { [FALLBACK_LOCALE]: { translation: {} } },
  interpolation: { escapeValue: false },
  react: { useSuspense: false },
});

/** Locales already downloaded (or being downloaded). */
const loaded = new Set<string>();

/**
 * Download (once) and activate a locale, then point `lang`/`dir` at it.
 *
 * `dir` is applied to `<html>` so RTL layouts work without a second mechanism
 * (the same rule as the theme: one attribute, one source of truth).
 */
export async function loadLocale(locale: string): Promise<I18nPayload> {
  let payload: I18nPayload | null = null;

  if (!loaded.has(locale)) {
    payload = await apiRequest<I18nPayload>(`/i18n${buildQuery({ locale })}`);
    i18n.addResourceBundle(locale, 'translation', payload.messages, true, true);
    loaded.add(locale);
  }

  if (i18n.resolvedLanguage !== locale) {
    await i18n.changeLanguage(locale);
  }

  const dir = payload?.dir ?? i18n.dir(locale);
  document.documentElement.lang = payload?.locale ?? locale;
  document.documentElement.dir = dir;

  return payload ?? { locale, dir: dir === 'rtl' ? 'rtl' : 'ltr', messages: {} };
}

/** Test/debug helper: forget cached catalogues. */
export function resetLocaleCache(): void {
  loaded.clear();
}

export { i18n };
