import { formatNumber } from './number';

/**
 * Bytes for DISPLAY: binary units, one decimal under ten, because plans and
 * files are sold in GB and MB. Shared by the plan meter and download pages —
 * two copies of a formatter drift into two answers for one file.
 *
 * The figure is in the reader's digits; the unit stays Latin, as it is on
 * every Bengali storage label too.
 */
export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${formatNumber(bytes)} B`;

  const units = ['KB', 'MB', 'GB', 'TB'];
  let value = bytes / 1024;
  let unit = 0;

  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }

  const digits = value >= 10 || unit === 0 ? 0 : 1;

  return `${formatNumber(value, { minimumFractionDigits: digits, maximumFractionDigits: digits })} ${units[unit]}`;
}
