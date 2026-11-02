import { describe, expect, it } from 'vitest';

import { applyTheme, readStoredTheme, resolveTheme, storeTheme, THEME_STORAGE_KEY } from '@/lib/theme';

describe('resolveTheme', () => {
  it('honours an explicit choice over the OS preference', () => {
    expect(resolveTheme('light', true)).toBe('light');
    expect(resolveTheme('dark', false)).toBe('dark');
  });

  it('follows the OS preference in system mode', () => {
    expect(resolveTheme('system', true)).toBe('dark');
    expect(resolveTheme('system', false)).toBe('light');
  });
});

describe('applyTheme', () => {
  it('writes data-theme on the root element (the only theme mechanism)', () => {
    const root = document.createElement('div');

    expect(applyTheme('dark', root)).toBe('dark');
    expect(root.getAttribute('data-theme')).toBe('dark');
    expect(root.getAttribute('data-theme-source')).toBe('user');

    applyTheme('system', root, false);
    expect(root.getAttribute('data-theme')).toBe('light');
    expect(root.getAttribute('data-theme-source')).toBe('system');
  });
});

describe('stored theme', () => {
  it('round-trips a user choice and clears it for system', () => {
    const store = window.localStorage;
    storeTheme('dark', store);
    expect(store.getItem(THEME_STORAGE_KEY)).toBe('dark');
    expect(readStoredTheme(store)).toBe('dark');

    storeTheme('system', store);
    expect(store.getItem(THEME_STORAGE_KEY)).toBeNull();
    expect(readStoredTheme(store)).toBe('system');
  });

  it('ignores garbage instead of breaking the shell', () => {
    const store = window.localStorage;
    store.setItem(THEME_STORAGE_KEY, 'neon');
    expect(readStoredTheme(store)).toBe('system');
    store.removeItem(THEME_STORAGE_KEY);
  });
});
