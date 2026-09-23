import { afterEach, describe, expect, it } from 'vitest';

import { formatDate } from '@/shared/lib/datetime';
import { formatMinor } from '@/shared/lib/money';
import { formatNumber } from '@/shared/lib/number';

import { applyLocale, ENGLISH, useLocaleStore } from './locale';
import { plural, t } from './t';
import type { ResolvedLocale } from './types';

const BENGALI: ResolvedLocale = {
  code: 'bn',
  native_name: 'বাংলা',
  direction: 'ltr',
  available: [ENGLISH, { code: 'bn', native_name: 'বাংলা', direction: 'ltr' }],
};

const MIRRORED: ResolvedLocale = { ...BENGALI, code: 'bn', direction: 'rtl' };

function speak(locale: ResolvedLocale, catalogue: Record<string, string> = {}) {
  useLocaleStore.setState({ active: locale, catalogue });
}

afterEach(() => {
  useLocaleStore.setState({ active: ENGLISH, catalogue: {} });
  document.documentElement.lang = '';
  document.documentElement.dir = '';
});

describe('t', () => {
  it('reads the English written beside the key when nothing is translated', () => {
    expect(t('learner.title', 'Continue learning')).toBe('Continue learning');
  });

  it('reads the catalogue when the key is translated', () => {
    speak(BENGALI, { 'learner.title': 'শেখা চালিয়ে যান' });

    expect(t('learner.title', 'Continue learning')).toBe('শেখা চালিয়ে যান');
  });

  it('fills parameters, and leaves an unknown one visible rather than blank', () => {
    expect(t('x.hi', 'Hello, {name}. {missing}', { name: 'Ada' })).toBe('Hello, Ada. {missing}');
  });
});

describe('plural', () => {
  const forms = { one: '{count} item', other: '{count} items' };

  it('picks the English category', () => {
    expect(plural('cart.lines', 1, forms)).toBe('1 item');
    expect(plural('cart.lines', 3, forms)).toBe('3 items');
  });

  it("writes the count in the reader's digits", () => {
    speak(BENGALI, { 'cart.lines.one': '{count}টি আইটেম', 'cart.lines.other': '{count}টি আইটেম' });

    expect(plural('cart.lines', 3, forms)).toBe('৩টি আইটেম');
  });
});

describe('formatting follows the resolved language, not the browser', () => {
  it('writes Bengali digits for a Bengali reader', () => {
    speak(BENGALI);

    expect(formatNumber(1234)).toBe('১,২৩৪');
    expect(formatMinor(50000, 'BDT')).toContain('৫০০');
    expect(formatDate('2026-09-24T10:00:00Z')).toMatch(/[০-৯]/);
  });

  it('writes Latin digits for an English reader', () => {
    expect(formatNumber(1234)).toBe('1,234');
  });
});

describe('applyLocale', () => {
  it('marks the document with the language and its direction', async () => {
    await applyLocale(MIRRORED);

    expect(document.documentElement.lang).toBe('bn');
    expect(document.documentElement.dir).toBe('rtl');
    expect(useLocaleStore.getState().active.direction).toBe('rtl');
  });

  it('lets the later of two switches win', async () => {
    const first = applyLocale(BENGALI);
    const second = applyLocale(ENGLISH);

    await Promise.all([first, second]);

    expect(useLocaleStore.getState().active.code).toBe('en');
  });
});
