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

/**
 * Catalogues downloaded so far, keyed by locale.
 *
 * The payload is kept (not just a "loaded" flag) because `dir` only exists in
 * the server's answer: `i18n.dir()` cannot know that `ar-SA` is RTL, so
 * switching back to a locale has to reuse the payload it was loaded with.
 */
const payloads = new Map<string, I18nPayload>();

/** Point i18n and `<html lang|dir>` at a locale, without fetching anything. */
async function activate(locale: string, payload: I18nPayload | null): Promise<void> {
  if (i18n.resolvedLanguage !== locale) {
    await i18n.changeLanguage(locale);
  }

  const dir = payload?.dir ?? i18n.dir(locale);
  document.documentElement.lang = payload?.locale ?? locale;
  document.documentElement.dir = dir;
}

/**
 * Download (once) and activate a locale, then point `lang`/`dir` at it.
 *
 * `dir` is applied to `<html>` so RTL layouts work without a second mechanism
 * (the same rule as the theme: one attribute, one source of truth).
 *
 * Idempotent and safe to call on every render: a locale that is already
 * downloaded costs one `changeLanguage`, and nothing when it is already active.
 * `SessionGate` relies on that when a user switches back to a locale whose
 * catalogue is still cached (`staleTime: Infinity` means the query will not run
 * again, and the previous locale would otherwise stay on screen).
 */
export async function loadLocale(locale: string): Promise<I18nPayload> {
  let payload = payloads.get(locale) ?? null;

  if (payload === null) {
    payload = await apiRequest<I18nPayload>(`/i18n${buildQuery({ locale })}`);
    i18n.addResourceBundle(locale, 'translation', payload.messages, true, true);
    payloads.set(locale, payload);
  }

  await activate(locale, payload);
  return payload;
}

/** Test/debug helper: forget cached catalogues. */
export function resetLocaleCache(): void {
  payloads.clear();
}

export { i18n };
