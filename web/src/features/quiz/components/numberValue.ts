/**
 * Mantine's NumberInput reports a string whenever the text is not yet a
 * canonical number — "070", "1.", "" — so a plain `typeof value === 'number'`
 * check silently turns a half-typed field into the fallback.
 */
export function numberValue(value: number | string, fallback: number): number {
  if (typeof value === 'number') return value;

  const parsed = Number(value);

  return value.trim() === '' || !Number.isFinite(parsed) ? fallback : parsed;
}

/** The same, for fields where empty means "no limit" rather than a number. */
export function optionalNumberValue(value: number | string): number | null {
  if (typeof value === 'number') return value;

  const parsed = Number(value);

  return value.trim() === '' || !Number.isFinite(parsed) ? null : parsed;
}
