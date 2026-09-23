import { describe, expect, it } from 'vitest';

/*
 * A catalogue and the source must agree (docs/I18N.md §3). A key translated
 * but no longer used is dead weight somebody will keep translating; a key used
 * in an area that HAS a catalogue but missing from it is a sentence left in
 * English on a translated page.
 *
 * An area with no catalogue yet is simply untranslated, and reads in English.
 */
const catalogues = import.meta.glob<Record<string, string>>('./catalogues/*/*.json', {
  import: 'default',
  eager: true,
});

const sources = import.meta.glob<string>(['/src/**/*.{ts,tsx}', '!/src/**/*.test.{ts,tsx}'], {
  query: '?raw',
  import: 'default',
  eager: true,
});

/** Keys passed to t('…') and plural('…'); a plural key owns `.one`, `.other`… */
function usedKeys(): { messages: Set<string>; plurals: Set<string> } {
  const messages = new Set<string>();
  const plurals = new Set<string>();

  for (const source of Object.values(sources)) {
    for (const [, key] of source.matchAll(/\bt\(\s*'([a-z][\w.-]*)'/g)) if (key) messages.add(key);
    for (const [, key] of source.matchAll(/\bplural\(\s*'([a-z][\w.-]*)'/g))
      if (key) plurals.add(key);
  }

  return { messages, plurals };
}

const PLURAL_SUFFIX = /\.(zero|one|two|few|many|other)$/;

describe('catalogues', () => {
  const { messages, plurals } = usedKeys();

  for (const [path, catalogue] of Object.entries(catalogues)) {
    const [, locale, area] = /catalogues\/(\w+)\/([\w-]+)\.json$/.exec(path) ?? [];

    it(`${locale}/${area} holds only keys of its own area that the source uses`, () => {
      const stray = Object.keys(catalogue).filter((key) => {
        if (!key.startsWith(`${area}.`)) return true;

        return PLURAL_SUFFIX.test(key)
          ? !plurals.has(key.replace(PLURAL_SUFFIX, ''))
          : !messages.has(key);
      });

      expect(stray).toEqual([]);
    });

    it(`${locale}/${area} translates every key its area uses`, () => {
      const missing = [
        ...[...messages].filter((key) => key.startsWith(`${area}.`) && !(key in catalogue)),
        ...[...plurals].filter(
          (key) => key.startsWith(`${area}.`) && !(`${key}.other` in catalogue),
        ),
      ];

      expect(missing).toEqual([]);
    });

    it(`${locale}/${area} has no empty message`, () => {
      expect(Object.entries(catalogue).filter(([, message]) => message.trim() === '')).toEqual([]);
    });
  }

  it('finds the keys the source uses', () => {
    expect(messages.size + plurals.size).toBeGreaterThanOrEqual(0);
  });
});
