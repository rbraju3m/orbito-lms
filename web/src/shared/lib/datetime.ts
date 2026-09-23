import { intlLocale } from '@/shared/i18n/locale';

/**
 * The edge where UTC becomes local time and back.
 *
 * `<input type="datetime-local">` speaks "YYYY-MM-DDTHH:mm" in the viewer's
 * own zone and has no concept of an offset; the API speaks ISO-8601 UTC. These
 * two functions are the only place that conversion happens, so a form cannot
 * accidentally post a local string as if it were UTC.
 */

/** ISO-8601 (any zone) → the value a datetime-local input expects. */
export function toLocalInputValue(iso: string | null | undefined): string {
  if (!iso) return '';

  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) return '';

  const pad = (n: number) => String(n).padStart(2, '0');

  return (
    `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
    `T${pad(date.getHours())}:${pad(date.getMinutes())}`
  );
}

/** A datetime-local value → ISO-8601, or null when the field is empty. */
export function fromLocalInputValue(value: string): string | null {
  if (value.trim() === '') return null;

  const date = new Date(value);

  return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

/** A short, readable rendering in the viewer's own zone and language. */
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—';

  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) return '—';

  return new Intl.DateTimeFormat(intlLocale(), {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date);
}

/** Date only, for a deadline or an expiry where the time of day is noise. */
export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—';

  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) return '—';

  return new Intl.DateTimeFormat(intlLocale(), { dateStyle: 'medium' }).format(date);
}

/** Time of day only, in the viewer's zone and language. */
export function formatTime(iso: string | null | undefined): string {
  if (!iso) return '—';

  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) return '—';

  return new Intl.DateTimeFormat(intlLocale(), { timeStyle: 'short' }).format(date);
}

/**
 * A CALENDAR DAY the server names as `YYYY-MM-DD` — an analytics range, a
 * rollup's day. Formatted in UTC, because that is the day it is: read as
 * local time it would be the day before for everybody west of Greenwich.
 */
export function formatDay(day: string | null | undefined): string {
  if (!day) return '—';

  const date = new Date(`${day}T00:00:00Z`);

  if (Number.isNaN(date.getTime())) return '—';

  return new Intl.DateTimeFormat(intlLocale(), { dateStyle: 'medium', timeZone: 'UTC' }).format(
    date,
  );
}
