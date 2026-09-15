import { queryOptions } from '@tanstack/react-query';

import type { NavLink, PublicPage } from '@/features/content/api/pageTypes';
import { apiGet } from '@/shared/api/client';
import { ApiError } from '@/shared/api/errors';

import { publicKeys } from './keys';

/** The academy's built pages, to a stranger. docs/PAGES.md. */

const base = (academy: string) => `/public/${encodeURIComponent(academy)}`;

export const publicPageQuery = (academy: string, slug: string) =>
  queryOptions({
    queryKey: publicKeys.page(academy, slug),
    queryFn: ({ signal }) =>
      apiGet<PublicPage>(`${base(academy)}/pages/${encodeURIComponent(slug)}`, { signal }),
    staleTime: 5 * 60_000,
  });

/**
 * The front page the academy chose, or NULL when it chose none (or has not
 * published it yet). A 404 here is an answer, not a failure: the site then
 * shows its standard front page.
 */
export const publicHomePageQuery = (academy: string) =>
  queryOptions({
    queryKey: publicKeys.home(academy),
    queryFn: async ({ signal }): Promise<PublicPage | null> => {
      try {
        return await apiGet<PublicPage>(`${base(academy)}/home`, { signal });
      } catch (error) {
        if (error instanceof ApiError && error.isNotFound) {
          return null;
        }

        throw error;
      }
    },
    staleTime: 5 * 60_000,
  });

/** The pages linked from the site's header. */
export const publicNavigationQuery = (academy: string) =>
  queryOptions({
    queryKey: publicKeys.navigation(academy),
    queryFn: ({ signal }) => apiGet<NavLink[]>(`${base(academy)}/navigation`, { signal }),
    staleTime: 10 * 60_000,
  });
