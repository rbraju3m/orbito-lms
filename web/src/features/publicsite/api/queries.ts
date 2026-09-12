import { queryOptions } from '@tanstack/react-query';

import type { CatalogFilters, Course, CourseListItem } from '@/features/catalog/api/types';
import type { Webinar } from '@/features/live/api/types';
import { apiGet, apiGetRaw } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

import { publicKeys } from './keys';
import type { PublicAcademy } from './types';

/**
 * The academy's public site. No auth, and the academy is in the PATH — the
 * only surface in the product that does not resolve its academy from the
 * signed-in user (docs/API.md, § the public site).
 */
const base = (academy: string) => `/public/${encodeURIComponent(academy)}`;

export const publicAcademyQuery = (academy: string) =>
  queryOptions({
    queryKey: publicKeys.academy(academy),
    queryFn: ({ signal }) => apiGet<PublicAcademy>(base(academy), { signal }),
    // The header of every page on the site, and it changes about never.
    staleTime: 10 * 60_000,
  });

export const publicCoursesQuery = (academy: string, filters: CatalogFilters = {}) =>
  queryOptions({
    queryKey: publicKeys.courses(academy, filters),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<CourseListItem>>(`${base(academy)}/courses`, {
        signal,
        params: filters,
      }),
    staleTime: 60_000,
  });

export const publicCourseQuery = (academy: string, slug: string) =>
  queryOptions({
    queryKey: publicKeys.course(academy, slug),
    queryFn: ({ signal }) => apiGet<Course>(`${base(academy)}/courses/${slug}`, { signal }),
    staleTime: 5 * 60_000,
  });

export const publicWebinarsQuery = (academy: string) =>
  queryOptions({
    queryKey: publicKeys.webinars(academy),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<Webinar>>(`${base(academy)}/webinars`, { signal }),
    staleTime: 60_000,
  });

export const publicWebinarQuery = (academy: string, slug: string) =>
  queryOptions({
    queryKey: publicKeys.webinar(academy, slug),
    queryFn: ({ signal }) => apiGet<Webinar>(`${base(academy)}/webinars/${slug}`, { signal }),
    staleTime: 60_000,
  });
