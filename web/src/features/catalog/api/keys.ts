import type { CatalogFilters } from './types';

/**
 * Key factories. The `lists()` / `details()` split matters: invalidating
 * `courses()` would also invalidate every detail entry, so a mutation that
 * writes a fresh detail into the cache would immediately have it refetched
 * away. Invalidate `lists()` when only the lists went stale.
 */
export const catalogKeys = {
  all: ['catalog'] as const,
  courses: () => [...catalogKeys.all, 'courses'] as const,
  lists: () => [...catalogKeys.courses(), 'list'] as const,
  list: (filters: CatalogFilters) => [...catalogKeys.lists(), filters] as const,
  details: () => [...catalogKeys.courses(), 'detail'] as const,
  detail: (slug: string) => [...catalogKeys.details(), slug] as const,
  categories: () => [...catalogKeys.all, 'categories'] as const,
};

export const studioKeys = {
  all: ['studio'] as const,
  courses: () => [...studioKeys.all, 'courses'] as const,
  lists: () => [...studioKeys.courses(), 'list'] as const,
  // Generic rather than Record<string, unknown> so a typed filter object stays
  // typed all the way into the key.
  list: <T>(filters: T) => [...studioKeys.lists(), filters] as const,
  details: () => [...studioKeys.courses(), 'detail'] as const,
  detail: (id: string) => [...studioKeys.details(), id] as const,
};
