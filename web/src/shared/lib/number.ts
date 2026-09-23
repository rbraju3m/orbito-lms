import { intlLocale } from '@/shared/i18n/locale';

/**
 * A count, in the reader's digits — ১,২৩৪ in Bengali (docs/I18N.md §2).
 *
 * Never `n.toLocaleString()`: with no argument that follows the BROWSER's
 * language, not the one the reader chose.
 */
export function formatNumber(n: number, options?: Intl.NumberFormatOptions): string {
  return new Intl.NumberFormat(intlLocale(), options).format(n);
}
