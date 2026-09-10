/**
 * The `lists()` / `details()` split, for the same reason as every other
 * feature: invalidating the root would refetch a detail a mutation had just
 * written into the cache.
 */
export const downloadKeys = {
  all: ['downloads'] as const,
  catalogue: () => [...downloadKeys.all, 'catalogue'] as const,
  catalogueList: (page: number) => [...downloadKeys.catalogue(), 'list', page] as const,
  catalogueDetail: (slug: string) => [...downloadKeys.catalogue(), 'detail', slug] as const,
  mine: (page: number) => [...downloadKeys.all, 'mine', page] as const,

  studio: () => [...downloadKeys.all, 'studio'] as const,
  studioLists: () => [...downloadKeys.studio(), 'list'] as const,
  studioList: <T>(filters: T) => [...downloadKeys.studioLists(), filters] as const,
  studioDetail: (id: string) => [...downloadKeys.studio(), 'detail', id] as const,
};
