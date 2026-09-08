/**
 * The window every analytics screen is asked for.
 *
 * PRESETS, not a date picker. Every option is a range the rollups can serve
 * cheaply, and none can ask for more than the API's 366-day cap — a free date
 * pair invites "since the beginning of time", which is a table scan somebody
 * asks for by accident.
 *
 * Separate from the component that renders them so the file exports only
 * components: a module mixing the two breaks fast refresh.
 */
export const RANGE_PRESETS = [
  { value: '7', label: '7 days' },
  { value: '30', label: '30 days' },
  { value: '90', label: '90 days' },
  { value: '365', label: '12 months' },
] as const;

export type RangePreset = (typeof RANGE_PRESETS)[number]['value'];

/** UTC, because that is what the rollups are keyed on. */
export function rangeFor(preset: RangePreset): { from: string; to: string } {
  const days = Number(preset);
  const today = new Date();
  const to = today.toISOString().slice(0, 10);

  const start = new Date(today);
  start.setUTCDate(start.getUTCDate() - (days - 1));

  return { from: start.toISOString().slice(0, 10), to };
}
