/**
 * Bytes for DISPLAY: binary units, one decimal under ten, because plans and
 * files are sold in GB and MB. Shared by the plan meter and download pages —
 * two copies of a formatter drift into two answers for one file.
 */
export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;

  const units = ['KB', 'MB', 'GB', 'TB'];
  let value = bytes / 1024;
  let unit = 0;

  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }

  return `${value.toFixed(value >= 10 || unit === 0 ? 0 : 1)} ${units[unit]}`;
}
