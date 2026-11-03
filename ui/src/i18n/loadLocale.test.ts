import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { I18nPayload } from '@/i18n';
import { i18n, loadLocale, resetLocaleCache } from '@/i18n';

/**
 * Locale switching.
 *
 * The catalogue is fetched once per locale and cached (`staleTime: Infinity` in
 * `SessionGate`), which is what makes a switch instant — and what used to break
 * the way *back*: the query never re-ran, so `es-MX → en-US` left the Spanish
 * strings on screen until a reload. These tests pin the activation down:
 * `loadLocale()` must switch i18n and `<html lang|dir>` every time it is called,
 * while only issuing one request per locale.
 */

const requests: string[] = [];

vi.mock('@/api/client', () => ({
  buildQuery: (params: Record<string, string>) => `?${new URLSearchParams(params).toString()}`,
  apiRequest: vi.fn(async (path: string) => {
    requests.push(path);
    const locale = new URLSearchParams(path.split('?')[1] ?? '').get('locale') ?? 'en-US';
    const payload: I18nPayload = {
      locale,
      dir: locale === 'ar-SA' ? 'rtl' : 'ltr',
      messages: { panel_title: `CMDB ${locale}` },
    };
    return payload;
  }),
}));

describe('loadLocale', () => {
  beforeEach(async () => {
    requests.length = 0;
    resetLocaleCache();
    await i18n.changeLanguage('en-US');
  });

  it('fetches each catalogue once and switches language + document attributes', async () => {
    await loadLocale('es-MX');

    expect(requests).toHaveLength(1);
    expect(i18n.resolvedLanguage).toBe('es-MX');
    expect(document.documentElement.lang).toBe('es-MX');
    expect(i18n.t('panel_title')).toBe('CMDB es-MX');
  });

  it('switches back to a cached locale without a second request', async () => {
    await loadLocale('es-MX');
    await loadLocale('en-US');
    await loadLocale('es-MX');

    expect(requests).toHaveLength(2); // en-US + es-MX
    expect(i18n.resolvedLanguage).toBe('es-MX');
    expect(i18n.t('panel_title')).toBe('CMDB es-MX');
  });

  it('restores the RTL direction from the cached payload, not from i18n', async () => {
    await loadLocale('ar-SA');
    await loadLocale('en-US');
    expect(document.documentElement.dir).toBe('ltr');

    await loadLocale('ar-SA');

    expect(requests).toHaveLength(2);
    expect(document.documentElement.dir).toBe('rtl');
    expect(document.documentElement.lang).toBe('ar-SA');
  });
});
