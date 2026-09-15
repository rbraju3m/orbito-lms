import { keepPreviousData, queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { apiDelete, apiGet, apiGetRaw, apiPatch, apiPost, apiPut } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

import type { AdminPage, AdminPageListItem, PageBlock, PageStatus } from './pageTypes';

/** The academy's built pages, for the people who build them (`page.manage`). */

export interface PageFilters {
  status: PageStatus | 'all';
  page: number;
}

export const pageKeys = {
  all: ['pages'] as const,
  lists: () => [...pageKeys.all, 'list'] as const,
  list: (filters: PageFilters) => [...pageKeys.lists(), filters] as const,
  detail: (id: string) => [...pageKeys.all, 'detail', id] as const,
};

export const adminPagesQuery = (filters: PageFilters) =>
  queryOptions({
    queryKey: pageKeys.list(filters),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<AdminPageListItem>>('/admin/pages', {
        signal,
        params: {
          page: filters.page,
          status: filters.status === 'all' ? undefined : filters.status,
        },
      }),
    staleTime: 30_000,
    placeholderData: keepPreviousData,
  });

export const adminPageQuery = (id: string) =>
  queryOptions({
    queryKey: pageKeys.detail(id),
    queryFn: ({ signal }) => apiGet<AdminPage>(`/admin/pages/${id}`, { signal }),
    staleTime: 30_000,
  });

export interface PageSettingsInput {
  title?: string;
  slug?: string;
  show_in_nav?: boolean;
  seo_title?: string | null;
  seo_description?: string | null;
}

/** What the builder sends: the whole list, without the server's resolved `data`. */
export type EditableBlock = Pick<PageBlock, 'id' | 'type'> & { props: Record<string, unknown> };

function usePageWrite<TVariables>(
  id: string,
  mutationFn: (variables: TVariables) => Promise<AdminPage>,
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: (page) => {
      queryClient.setQueryData(pageKeys.detail(id), page);
      void queryClient.invalidateQueries({ queryKey: pageKeys.lists() });
    },
  });
}

export function useCreatePage() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { title: string }) => apiPost<AdminPage>('/admin/pages', input),
    onSuccess: (page) => {
      queryClient.setQueryData(pageKeys.detail(page.id), page);
      void queryClient.invalidateQueries({ queryKey: pageKeys.lists() });
    },
  });
}

export function useUpdatePage(id: string) {
  return usePageWrite(id, (input: PageSettingsInput) =>
    apiPatch<AdminPage>(`/admin/pages/${id}`, input),
  );
}

/**
 * The WHOLE list, replaced (docs/PAGES.md §2). Not optimistic: the server
 * normalises and resolves what comes back, and that is what the preview shows.
 */
export function useSavePageBlocks(id: string) {
  return usePageWrite(id, (blocks: EditableBlock[]) =>
    apiPut<AdminPage>(`/admin/pages/${id}/blocks`, { blocks }),
  );
}

export function usePublishPage(id: string) {
  return usePageWrite(id, () => apiPost<AdminPage>(`/admin/pages/${id}/publish`));
}

export function useUnpublishPage(id: string) {
  return usePageWrite(id, () => apiPost<AdminPage>(`/admin/pages/${id}/unpublish`));
}

export function useSetHomePage(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => apiPost<AdminPage>(`/admin/pages/${id}/home`),
    onSuccess: (page) => {
      queryClient.setQueryData(pageKeys.detail(id), page);
      // Another page just stopped being the front page.
      void queryClient.invalidateQueries({ queryKey: pageKeys.all });
    },
  });
}

export function useClearHomePage(id: string) {
  return usePageWrite(id, () => apiDelete<AdminPage>(`/admin/pages/${id}/home`));
}

export function useDeletePage() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => apiDelete(`/admin/pages/${id}`),
    onSuccess: (_result, id) => {
      queryClient.removeQueries({ queryKey: pageKeys.detail(id) });
      void queryClient.invalidateQueries({ queryKey: pageKeys.lists() });
    },
  });
}
