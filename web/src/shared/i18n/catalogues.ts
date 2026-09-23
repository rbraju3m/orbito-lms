import type { Catalogue } from './types';

/*
 * Every translated catalogue, one lazy chunk per LOCALE per AREA —
 * `catalogues/bn/learner.json` holds the keys starting `learner.`. English
 * has no catalogue: it is the default text written beside each key, so an
 * English reader downloads nothing (docs/I18N.md §2).
 */
const loaders = import.meta.glob<Catalogue>('./catalogues/*/*.json', { import: 'default' });

/**
 * Loads every area's catalogue for a locale, merged.
 *
 * All areas at once, BEFORE the switch: a page that changed language
 * paragraph by paragraph as chunks arrived would be worse than a short wait.
 * If the catalogues ever grow large enough to matter, split by area here —
 * callers do not change.
 */
export async function loadCatalogue(code: string): Promise<Catalogue> {
  const prefix = `./catalogues/${code}/`;
  const parts = await Promise.all(
    Object.entries(loaders)
      .filter(([path]) => path.startsWith(prefix))
      .map(([, load]) => load()),
  );

  return Object.assign({}, ...parts) as Catalogue;
}
