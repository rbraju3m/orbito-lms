/** One language, as the server describes it (`/auth/me` → `locale`). */
export interface LocaleInfo {
  code: string;
  native_name: string;
  direction: 'ltr' | 'rtl';
}

/** What the reader gets, and what they could switch to. */
export interface ResolvedLocale extends LocaleInfo {
  available: LocaleInfo[];
}

/** A flat map of key → message. `{name}` marks a parameter. */
export type Catalogue = Record<string, string>;
