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

/** A short, readable rendering in the viewer's own zone. */
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—';

  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) return '—';

  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date);
}

/** Date only, for a deadline or an expiry where the time of day is noise. */
export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—';

  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) return '—';

  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(date);
}
