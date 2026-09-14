import { queryOptions } from '@tanstack/react-query';

import type { Post, PostListItem } from '@/features/content/api/postTypes';
import { apiGet, apiGetRaw } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

import { publicKeys } from './keys';

/** The academy's blog, to a stranger. docs/BLOG.md. */

const base = (academy: string) => `/public/${encodeURIComponent(academy)}`;

export const publicPostsQuery = (academy: string, page = 1) =>
  queryOptions({
    queryKey: publicKeys.posts(academy, page),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<PostListItem>>(`${base(academy)}/posts`, { signal, params: { page } }),
    staleTime: 60_000,
  });

export const publicPostQuery = (academy: string, slug: string) =>
  queryOptions({
    queryKey: publicKeys.post(academy, slug),
    queryFn: ({ signal }) =>
      apiGet<Post>(`${base(academy)}/posts/${encodeURIComponent(slug)}`, { signal }),
    staleTime: 5 * 60_000,
  });
