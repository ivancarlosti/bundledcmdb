import { readFileSync, readdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

/**
 * i18n coverage, in the SPA.
 *
 * `bin/check-i18n.php` only sees keys written as `lang('key')` in PHP, so a key
 * that the React code asks for and the catalogue lacks would ship as a raw
 * `ui_something` on screen. This test reads the same PHP catalogues the server
 * serves through `GET /i18n` and checks the keys the SPA actually references.
 *
 * It is deliberately literal-only: `t(`role_${role}`)` and friends are covered by
 * the explicit family assertions at the bottom of the file.
 */

/** Vitest runs with the project root (`ui/`) as cwd. */
const uiRoot = process.cwd();
const srcRoot = resolve(uiRoot, 'src');
const langRoot = resolve(uiRoot, '..', 'public', 'lang');

/** `'key' => 'value',` lines of a PHP catalogue. */
function readCatalog(locale: string): Map<string, string> {
  const source = readFileSync(join(langRoot, `${locale}.php`), 'utf8');
  const entries = new Map<string, string>();
  const pattern = /^\s*(['"])([A-Za-z0-9_.]+)\1\s*=>/gm;
  for (const match of source.matchAll(pattern)) {
    entries.set(match[2] as string, '');
  }
  return entries;
}

function sourceFiles(dir: string): string[] {
  return readdirSync(dir, { recursive: true, encoding: 'utf8' })
    .filter((name) => /\.(ts|tsx)$/.test(name) && !name.includes('.test.'))
    .map((name) => join(dir, name));
}

/** Literal keys referenced through `t('key')`, `t("key")` and `i18nKey="key"`. */
function usedKeys(): Set<string> {
  const keys = new Set<string>();
  const patterns = [/\bt\(\s*'([A-Za-z0-9_.]+)'/g, /\bt\(\s*"([A-Za-z0-9_.]+)"/g, /i18nKey="([A-Za-z0-9_.]+)"/g];

  for (const file of sourceFiles(srcRoot)) {
    const source = readFileSync(file, 'utf8');
    for (const pattern of patterns) {
      for (const match of source.matchAll(pattern)) {
        keys.add(match[1] as string);
      }
    }
  }
  return keys;
}

const enUS = readCatalog('en-US');
const used = usedKeys();

describe('i18n catalogues', () => {
  it('ships every key the SPA asks for', () => {
    expect(used.size).toBeGreaterThan(50);
    const missing = [...used].filter((key) => !enUS.has(key)).sort();
    expect(missing, `missing from public/lang/en-US.php: ${missing.join(', ')}`).toEqual([]);
  });

  it('never defines a key en-US does not have (typo guard)', () => {
    for (const locale of ['pt-BR', 'es-MX', 'fr-FR', 'zh-CN', 'hi-IN', 'ar-SA']) {
      const unknown = [...readCatalog(locale).keys()].filter((key) => !enUS.has(key)).sort();
      expect(unknown, `${locale} defines keys unknown to en-US: ${unknown.join(', ')}`).toEqual([]);
    }
  });

  it('covers the dynamic key families the grid and shell build at runtime', () => {
    const families = ['role_', 'status_', 'theme_', 'col_'];
    for (const family of families) {
      const matches = [...enUS.keys()].filter((key) => key.startsWith(family));
      expect(matches.length, `${family}* has no keys in en-US`).toBeGreaterThan(0);
    }
    // Every role the session endpoint can report.
    for (const role of ['user', 'manager', 'admin', 'superadmin']) {
      expect(enUS.has(`role_${role}`), `role_${role} is missing`).toBe(true);
    }
  });
});
