/**
 * Locale-aware formatting for grid and detail cells.
 *
 * The API returns ISO-8601 UTC strings and typed values; formatting happens in
 * the browser with `Intl` so a date is rendered in the user's language without
 * sending pre-formatted strings (which is what made the legacy grid untestable
 * and locale-blind).
 */

const dateTimeCache = new Map<string, Intl.DateTimeFormat>();
const dateCache = new Map<string, Intl.DateTimeFormat>();
const numberCache = new Map<string, Intl.NumberFormat>();

function dateTimeFormatter(locale: string): Intl.DateTimeFormat {
  const cached = dateTimeCache.get(locale);
  if (cached !== undefined) {
    return cached;
  }
  const created = new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' });
  dateTimeCache.set(locale, created);
  return created;
}

function dateFormatter(locale: string): Intl.DateTimeFormat {
  const cached = dateCache.get(locale);
  if (cached !== undefined) {
    return cached;
  }
  const created = new Intl.DateTimeFormat(locale, { dateStyle: 'medium' });
  dateCache.set(locale, created);
  return created;
}

function numberFormatter(locale: string): Intl.NumberFormat {
  const cached = numberCache.get(locale);
  if (cached !== undefined) {
    return cached;
  }
  const created = new Intl.NumberFormat(locale);
  numberCache.set(locale, created);
  return created;
}

/** Parse an ISO string (or return null for empty/garbage/absent values). */
export function toDate(value: unknown): Date | null {
  if (typeof value !== 'string' || value.trim() === '') {
    return null;
  }
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
}

export function formatDateTime(value: unknown, locale: string, fallback = '—'): string {
  const date = toDate(value);
  return date === null ? fallback : dateTimeFormatter(locale).format(date);
}

export function formatDate(value: unknown, locale: string, fallback = '—'): string {
  const date = toDate(value);
  return date === null ? fallback : dateFormatter(locale).format(date);
}

export function formatNumber(value: unknown, locale: string, fallback = '—'): string {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return numberFormatter(locale).format(value);
  }
  if (typeof value === 'string' && value.trim() !== '' && !Number.isNaN(Number(value))) {
    return numberFormatter(locale).format(Number(value));
  }
  return fallback;
}

export function formatBytes(size: unknown, locale: string, fallback = '—'): string {
  if (typeof size !== 'number' || !Number.isFinite(size) || size < 0) {
    return fallback;
  }
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let value = size;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }
  const digits = unit === 0 ? 0 : 1;
  return `${numberFormatter(locale).format(Number(value.toFixed(digits)))} ${units[unit]}`;
}
