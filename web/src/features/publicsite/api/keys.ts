import type { CatalogFilters } from '@/features/catalog/api/types';

/**
 * Keys for the anonymous surface, every one of them scoped by ACADEMY slug.
 *
 * That scoping is not cosmetic: one browser can read two academies' sites in
 * one session, and a key that omitted the slug would serve the second academy
 * the first one's courses out of cache.
 */
export const publicKeys = {
  all: ['public'] as const,
  academy: (academy: string) => [...publicKeys.all, academy] as const,
  courses: (academy: string, filters: CatalogFilters) =>
    [...publicKeys.academy(academy), 'courses', filters] as const,
  course: (academy: string, slug: string) =>
    [...publicKeys.academy(academy), 'course', slug] as const,
  webinars: (academy: string) => [...publicKeys.academy(academy), 'webinars'] as const,
  webinar: (academy: string, slug: string) =>
    [...publicKeys.academy(academy), 'webinar', slug] as const,
};
