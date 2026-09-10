import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { apiDelete, apiGet, apiGetRaw, apiPatch, apiPost, apiPut } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

import { bundleKeys } from './keys';
import type { Bundle, BundleListItem, BundleStatus, StoredPrice } from './types';

/* ------------------------------------------------------------- catalogue */

export const bundleCatalogueQuery = (page = 1) =>
  queryOptions({
    queryKey: bundleKeys.catalogueList(page),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<BundleListItem>>('/bundles', { signal, params: { page } }),
    staleTime: 30_000,
  });

export const bundleQuery = (slug: string) =>
  queryOptions({
    queryKey: bundleKeys.catalogueDetail(slug),
    queryFn: ({ signal }) => apiGet<Bundle>(`/bundles/${slug}`, { signal }),
    staleTime: 30_000,
  });

/* ---------------------------------------------------------------- studio */

export interface StudioBundleFilters {
  status?: BundleStatus | '';
  page?: number;
}

export const studioBundlesQuery = (filters: StudioBundleFilters) =>
  queryOptions({
    queryKey: bundleKeys.studioList(filters),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<BundleListItem>>('/studio/bundles', { signal, params: filters }),
    staleTime: 30_000,
  });

export const studioBundleQuery = (id: string) =>
  queryOptions({
    queryKey: bundleKeys.studioDetail(id),
    queryFn: ({ signal }) => apiGet<Bundle>(`/studio/bundles/${id}`, { signal }),
    staleTime: 30_000,
  });

export interface BundlePayload {
  title?: string;
  subtitle?: string | null;
  description?: string | null;
  thumbnail_media_id?: number | null;
  /**
   * The WHOLE collection, never a delta — the server replaces rather than
   * merges, so that two people editing one bundle cannot interleave into a
   * set neither asked for.
   */
  course_ids?: number[];
}

export function useCreateBundle() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: BundlePayload) => apiPost<Bundle>('/studio/bundles', payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: bundleKeys.studioLists() });
    },
  });
}

export function useUpdateBundle(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: BundlePayload) => apiPatch<Bundle>(`/studio/bundles/${id}`, payload),
    /*
     * Never optimistic. What a bundle contains is what somebody gets charged
     * for, so a wrong answer held for a moment is not harmless — the rule
     * this codebase applies to payments, grading and publishing.
     */
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: bundleKeys.studioDetail(id) });
      void queryClient.invalidateQueries({ queryKey: bundleKeys.studioLists() });
    },
  });
}

export function useSetBundlePrice(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: { currency: string; amount_minor: number }) =>
      apiPut<StoredPrice>(`/studio/bundles/${id}/price`, payload),
    onSuccess: () => {
      // The checklist reads the price, so the detail has to come back too.
      void queryClient.invalidateQueries({ queryKey: bundleKeys.studioDetail(id) });
    },
  });
}

export function useChangeBundleStatus(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (action: 'publish' | 'unpublish' | 'archive') =>
      apiPost<Bundle>(`/studio/bundles/${id}/${action}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: bundleKeys.all });
    },
  });
}

export function useDeleteBundle() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => apiDelete<void>(`/studio/bundles/${id}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: bundleKeys.all });
    },
  });
}
