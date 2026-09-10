/**
 * Money is integer minor units plus an ISO currency (ADR-04). It is never a
 * float anywhere in this system, and it must not become one here either —
 * dividing by 100 is fine for DISPLAY because the result is immediately
 * handed to Intl and discarded, but the divided value is never stored,
 * compared, or summed.
 *
 * Sums happen in minor units, before formatting. `formatMinor(a) + formatMinor(b)`
 * is a string concatenation bug; `formatMinor(a + b)` is the intent.
 */

/** Currencies with no minor unit at all, where 500 means 500, not 5.00. */
const ZERO_DECIMAL = new Set(['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF']);

function fractionDigits(currency: string): number {
  return ZERO_DECIMAL.has(currency.toUpperCase()) ? 0 : 2;
}

/**
 * Formats minor units for display.
 *
 * The locale is deliberately the browser's rather than the academy's: the
 * CURRENCY is a fact about the price and comes from the server, but how a
 * reader expects thousands and decimals to be punctuated is a fact about the
 * reader.
 */
export function formatMinor(amountMinor: number, currency: string): string {
  const digits = fractionDigits(currency);

  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency,
      minimumFractionDigits: digits,
      maximumFractionDigits: digits,
    }).format(amountMinor / 10 ** digits);
  } catch {
    // An unknown or malformed code must not blank the price. Showing
    // "4900 XYZ" is worse than a formatted figure and far better than nothing.
    return `${(amountMinor / 10 ** digits).toFixed(digits)} ${currency}`;
  }
}

/** True when the price is zero — free, as distinct from absent. */
export function isFree(amountMinor: number | null | undefined): boolean {
  return amountMinor === 0;
}

/**
 * A figure somebody TYPED ("12.50") into minor units (1250), for sending.
 *
 * Rounded, because 19.99 × 100 is 1998.9999… in binary floating point, and
 * truncating it would charge a minor unit less than the person wrote. The
 * result is an integer and is the only form the API accepts (ADR-04).
 */
export function toMinor(major: number, currency: string): number {
  return Math.round(major * 10 ** fractionDigits(currency));
}

/** Minor units back into the figure a person edits — for a form's default only. */
export function toMajor(amountMinor: number, currency: string): number {
  return amountMinor / 10 ** fractionDigits(currency);
}
