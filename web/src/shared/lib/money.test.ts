import { describe, expect, it } from 'vitest';

import { toMajor, toMinor } from './money';

describe('toMinor', () => {
  it('rounds rather than truncating a figure binary floating point cannot hold', () => {
    // 19.99 * 100 === 1998.9999999999998
    expect(toMinor(19.99, 'BDT')).toBe(1999);
  });

  it('knows a currency with no minor unit', () => {
    expect(toMinor(500, 'JPY')).toBe(500);
  });
});

describe('toMajor', () => {
  it('is the inverse, for a form default', () => {
    expect(toMajor(1250, 'usd')).toBe(12.5);
    expect(toMajor(500, 'JPY')).toBe(500);
  });
});
