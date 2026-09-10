/**
 * The `lists()` / `details()` split matters for the same reason it does in
 * `catalogKeys`: invalidating the root would refetch a detail a mutation had
 * just written into the cache.
 */
export const bundleKeys = {
  all: ['bundles'] as const,
  catalogue: () => [...bundleKeys.all, 'catalogue'] as const,
  catalogueList: (page: number) => [...bundleKeys.catalogue(), 'list', page] as const,
  catalogueDetail: (slug: string) => [...bundleKeys.catalogue(), 'detail', slug] as const,

  studio: () => [...bundleKeys.all, 'studio'] as const,
  studioLists: () => [...bundleKeys.studio(), 'list'] as const,
  studioList: <T>(filters: T) => [...bundleKeys.studioLists(), filters] as const,
  studioDetails: () => [...bundleKeys.studio(), 'detail'] as const,
  studioDetail: (id: string) => [...bundleKeys.studioDetails(), id] as const,
};
