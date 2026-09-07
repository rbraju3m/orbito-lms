import { queryOptions } from '@tanstack/react-query';

import { apiGet, apiGetRaw } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

import { catalogKeys } from './keys';
import type { CatalogFilters, Course, CourseCategory, CourseListItem } from './types';

export const courseCatalogQuery = (filters: CatalogFilters) =>
  queryOptions({
    queryKey: catalogKeys.list(filters),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<CourseListItem>>('/courses', { signal, params: filters }),
    staleTime: 60_000,
  });

export const courseDetailQuery = (slug: string) =>
  queryOptions({
    queryKey: catalogKeys.detail(slug),
    queryFn: ({ signal }) => apiGet<Course>(`/courses/${slug}`, { signal }),
    staleTime: 5 * 60_000,
  });

export const categoriesQuery = () =>
  queryOptions({
    queryKey: catalogKeys.categories(),
    queryFn: ({ signal }) => apiGet<CourseCategory[]>('/categories', { signal }),
    // Taxonomy is near-static; refetching it on every catalogue visit is waste.
    staleTime: 60 * 60_000,
  });
