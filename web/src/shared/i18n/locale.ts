import { create } from 'zustand';

import { loadCatalogue } from './catalogues';
import type { Catalogue, LocaleInfo, ResolvedLocale } from './types';

export const ENGLISH: ResolvedLocale = {
  code: 'en',
  native_name: 'English',
  direction: 'ltr',
  available: [{ code: 'en', native_name: 'English', direction: 'ltr' }],
};

interface LocaleState {
  /** What the page is drawn in. Changes only once its catalogue is loaded. */
  active: ResolvedLocale;
  catalogue: Catalogue;
}

/**
 * The reader's language — CLIENT state holding a SERVER answer.
 *
 * The server resolves the locale (`LocaleResolver`); this only remembers
 * which answer the page is currently drawn in, because formatting and `t()`
 * are plain functions that cannot each subscribe to a query. It is never
 * decided here.
 */
export const useLocaleStore = create<LocaleState>(() => ({
  active: ENGLISH,
  catalogue: {},
}));

let pending: string | null = null;

/**
 * Switches the page to the server's answer, once its catalogue has arrived.
 * A second call while one is loading wins; the first is dropped when it lands.
 */
export async function applyLocale(next: ResolvedLocale): Promise<void> {
  const current = useLocaleStore.getState().active;

  // Claimed BEFORE the no-op check: switching back to the current language
  // must still cancel a slower switch away from it that is in flight.
  pending = next.code;

  if (sameLocale(current, next)) return;
  const catalogue = next.code === 'en' ? {} : await loadCatalogue(next.code).catch(() => ({}));

  if (pending !== next.code) return;

  useLocaleStore.setState({ active: next, catalogue });

  document.documentElement.lang = next.code;
  document.documentElement.dir = next.direction;
}

function sameLocale(a: ResolvedLocale, b: ResolvedLocale): boolean {
  const codes = (locale: ResolvedLocale) => locale.available.map((option) => option.code).join();

  return a.code === b.code && a.direction === b.direction && codes(a) === codes(b);
}

/** The active locale's code, for `Intl`. `bn` gives Bengali digits (১২৩). */
export function intlLocale(): string {
  return useLocaleStore.getState().active.code;
}

export function activeLocale(): LocaleInfo {
  return useLocaleStore.getState().active;
}
