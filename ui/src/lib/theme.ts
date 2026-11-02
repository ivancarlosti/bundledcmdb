/**
 * Theme handling — one mechanism only.
 *
 * The resolved theme is written to `data-theme` on `<html>`; `styles/tokens.css`
 * binds Tailwind's `dark:` variant to that attribute. There is no `body.dark`
 * class and no second source of truth (the mistake that made dark mode a no-op
 * after 2279453).
 *
 * Precedence: explicit user choice (localStorage) → server preference
 * (`user_preferences.theme`) → `system`.
 */

export type ThemeMode = 'system' | 'light' | 'dark';
export type ResolvedTheme = 'light' | 'dark';

export const THEME_STORAGE_KEY = 'bcmdb.theme';
export const THEME_MODES: readonly ThemeMode[] = ['system', 'light', 'dark'];

export function isThemeMode(value: unknown): value is ThemeMode {
  return typeof value === 'string' && (THEME_MODES as readonly string[]).includes(value);
}

/** Resolve a mode to the theme that must be painted. */
export function resolveTheme(mode: ThemeMode, prefersDark: boolean): ResolvedTheme {
  if (mode === 'dark') {
    return 'dark';
  }
  if (mode === 'light') {
    return 'light';
  }
  return prefersDark ? 'dark' : 'light';
}

/** Read the stored mode, falling back to `system` for missing/garbage values. */
export function readStoredTheme(storage?: Storage): ThemeMode {
  const target = storage ?? safeLocalStorage();
  if (!target) {
    return 'system';
  }
  try {
    const stored = target.getItem(THEME_STORAGE_KEY);
    return isThemeMode(stored) ? stored : 'system';
  } catch {
    return 'system';
  }
}

/** Persist the mode (best effort: private mode throws on write). */
export function storeTheme(mode: ThemeMode, storage?: Storage): void {
  const target = storage ?? safeLocalStorage();
  if (!target) {
    return;
  }
  try {
    if (mode === 'system') {
      target.removeItem(THEME_STORAGE_KEY);
    } else {
      target.setItem(THEME_STORAGE_KEY, mode);
    }
  } catch {
    /* ignore quota/security errors */
  }
}

/**
 * Apply a mode to the document and return the resolved theme.
 *
 * @param mode       requested mode
 * @param root       element that carries `data-theme` (html in the app)
 * @param prefersDark current OS preference; defaults to `matchMedia`
 */
export function applyTheme(
  mode: ThemeMode,
  root: HTMLElement = document.documentElement,
  prefersDark?: boolean,
): ResolvedTheme {
  const dark =
    prefersDark ??
    (typeof window !== 'undefined' && typeof window.matchMedia === 'function'
      ? window.matchMedia('(prefers-color-scheme: dark)').matches
      : false);

  const resolved = resolveTheme(mode, dark);
  root.setAttribute('data-theme', resolved);
  root.setAttribute('data-theme-source', mode === 'system' ? 'system' : 'user');

  return resolved;
}

function safeLocalStorage(): Storage | undefined {
  try {
    return typeof window === 'undefined' ? undefined : window.localStorage;
  } catch {
    return undefined;
  }
}
