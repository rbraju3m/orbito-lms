import { keepPreviousData, queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { apiDelete, apiGet, apiGetRaw, apiPatch, apiPost } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

import type { AdminPost, AdminPostListItem, PostStatus } from './postTypes';

/** The academy's blog, for the people who write it (`post.manage`). */

export interface PostFilters {
  status: PostStatus | 'all';
  page: number;
}

export const postKeys = {
  all: ['posts'] as const,
  lists: () => [...postKeys.all, 'list'] as const,
  list: (filters: PostFilters) => [...postKeys.lists(), filters] as const,
  detail: (id: string) => [...postKeys.all, 'detail', id] as const,
};

export const adminPostsQuery = (filters: PostFilters) =>
  queryOptions({
    queryKey: postKeys.list(filters),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<AdminPostListItem>>('/admin/posts', {
        signal,
        params: {
          page: filters.page,
          status: filters.status === 'all' ? undefined : filters.status,
        },
      }),
    staleTime: 30_000,
    placeholderData: keepPreviousData,
  });

export const adminPostQuery = (id: string) =>
  queryOptions({
    queryKey: postKeys.detail(id),
    queryFn: ({ signal }) => apiGet<AdminPost>(`/admin/posts/${id}`, { signal }),
    staleTime: 30_000,
  });

export interface PostInput {
  title?: string;
  slug?: string;
  excerpt?: string | null;
  body?: string | null;
  cover_media_id?: number | null;
  seo_title?: string | null;
  seo_description?: string | null;
}

function usePostWrite<TVariables>(
  id: string,
  mutationFn: (variables: TVariables) => Promise<AdminPost>,
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: (post) => {
      queryClient.setQueryData(postKeys.detail(id), post);
      void queryClient.invalidateQueries({ queryKey: postKeys.lists() });
    },
  });
}

export function useCreatePost() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: PostInput) => apiPost<AdminPost>('/admin/posts', input),
    onSuccess: (post) => {
      queryClient.setQueryData(postKeys.detail(post.id), post);
      void queryClient.invalidateQueries({ queryKey: postKeys.lists() });
    },
  });
}

export function useUpdatePost(id: string) {
  return usePostWrite(id, (input: PostInput) => apiPatch<AdminPost>(`/admin/posts/${id}`, input));
}

/**
 * Now, or at `publishedAt` — a future instant schedules it. Never optimistic:
 * whether it may go out (a post with nothing in it may not) is the server's
 * answer (CLAUDE.md §5).
 */
export function usePublishPost(id: string) {
  return usePostWrite(id, (publishedAt: string | null) =>
    apiPost<AdminPost>(
      `/admin/posts/${id}/publish`,
      publishedAt === null ? {} : { published_at: publishedAt },
    ),
  );
}

export function useUnpublishPost(id: string) {
  return usePostWrite(id, () => apiPost<AdminPost>(`/admin/posts/${id}/unpublish`));
}

export function useDeletePost() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => apiDelete(`/admin/posts/${id}`),
    onSuccess: (_result, id) => {
      queryClient.removeQueries({ queryKey: postKeys.detail(id) });
      void queryClient.invalidateQueries({ queryKey: postKeys.lists() });
    },
  });
}
