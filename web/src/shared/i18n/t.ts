import { intlLocale, useLocaleStore } from './locale';

type Params = Record<string, string | number>;

/**
 * A message in the reader's language, or the English written beside it.
 *
 *   t('learner.dashboard.title', 'Continue learning')
 *   t('cart.lines', '{count} items', { count: formatNumber(3) })
 *
 * The English default lives in the source, so a key with no translation yet
 * reads exactly as it did before — and nothing is ever blank. The key's first
 * segment is its AREA and names the catalogue file it belongs in.
 *
 * Never build a sentence by concatenating `t()` calls: word order differs
 * between languages. One key, with parameters.
 */
export function t(key: string, fallback: string, params?: Params): string {
  const message = useLocaleStore.getState().catalogue[key] ?? fallback;

  return params === undefined ? message : interpolate(message, params);
}

/**
 * A message that depends on a count. `forms` is keyed by the plural
 * CATEGORY — English needs `one` and `other`; Bengali has only `other` for
 * the purpose, and some languages have four. `n === 1` is not a rule.
 *
 *   plural('cart.lines', n, { one: '{count} item', other: '{count} items' })
 */
export function plural(
  key: string,
  n: number,
  forms: { one?: string; other: string },
  params?: Params,
): string {
  const category = new Intl.PluralRules(intlLocale()).select(n);
  const fallback = (category === 'one' ? forms.one : undefined) ?? forms.other;
  const catalogue = useLocaleStore.getState().catalogue;
  const message = catalogue[`${key}.${category}`] ?? catalogue[`${key}.other`] ?? fallback;

  return interpolate(message, { count: new Intl.NumberFormat(intlLocale()).format(n), ...params });
}

function interpolate(message: string, params: Params): string {
  return message.replace(/\{(\w+)\}/g, (match, name: string) =>
    name in params ? String(params[name]) : match,
  );
}
