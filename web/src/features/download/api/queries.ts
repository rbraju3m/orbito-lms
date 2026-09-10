import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { apiDelete, apiGet, apiGetRaw, apiPatch, apiPost, apiPut } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

import { downloadKeys } from './keys';
import type { Download, DownloadLink, DownloadListItem, DownloadPricing, DownloadStatus } from './types';

/* ------------------------------------------------------------- catalogue */

export const downloadCatalogueQuery = (page = 1) =>
  queryOptions({
    queryKey: downloadKeys.catalogueList(page),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<DownloadListItem>>('/downloads', { signal, params: { page } }),
    staleTime: 30_000,
  });

export const downloadQuery = (slug: string) =>
  queryOptions({
    queryKey: downloadKeys.catalogueDetail(slug),
    queryFn: ({ signal }) => apiGet<Download>(`/downloads/${slug}`, { signal }),
    staleTime: 30_000,
  });

/** What the reader owns — archived ones included; archiving never takes a file back. */
export const myDownloadsQuery = (page = 1) =>
  queryOptions({
    queryKey: downloadKeys.mine(page),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<DownloadListItem>>('/downloads/mine', { signal, params: { page } }),
    staleTime: 30_000,
  });

export function useClaimDownload(slug: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => apiPost<Download>(`/downloads/${slug}/claim`),
    onSuccess: (download) => {
      queryClient.setQueryData(downloadKeys.catalogueDetail(slug), download);
      void queryClient.invalidateQueries({ queryKey: downloadKeys.all });
    },
  });
}

/**
 * Mints a fresh 15-minute link and starts the download.
 *
 * A mutation, not a query: the link must never sit in the cache, where a
 * stale one would be handed out after it expired. `location.assign` rather
 * than `window.open`, because a popup opened after an `await` is exactly what
 * browsers block — and the response is an attachment, so the page stays put.
 */
export function useFetchDownload() {
  return useMutation({
    mutationFn: (slug: string) => apiGet<DownloadLink>(`/downloads/${slug}/file`),
    onSuccess: (link) => {
      window.location.assign(link.url);
    },
  });
}

/* ---------------------------------------------------------------- studio */

export interface StudioDownloadFilters {
  status?: DownloadStatus | '';
  page?: number;
}

export const studioDownloadsQuery = (filters: StudioDownloadFilters) =>
  queryOptions({
    queryKey: downloadKeys.studioList(filters),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<DownloadListItem>>('/studio/downloads', { signal, params: filters }),
    staleTime: 30_000,
  });

export const studioDownloadQuery = (id: string) =>
  queryOptions({
    queryKey: downloadKeys.studioDetail(id),
    queryFn: ({ signal }) => apiGet<Download>(`/studio/downloads/${id}`, { signal }),
    staleTime: 30_000,
  });

export interface DownloadPayload {
  title?: string;
  subtitle?: string | null;
  description?: string | null;
  /** Numeric media ref, from an upload into the `download` collection. */
  media_id?: number | null;
  thumbnail_media_id?: number | null;
  pricing_model?: DownloadPricing;
}

export function useCreateDownload() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: DownloadPayload) => apiPost<Download>('/studio/downloads', payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: downloadKeys.studioLists() });
    },
  });
}

export function useUpdateDownload(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: DownloadPayload) => apiPatch<Download>(`/studio/downloads/${id}`, payload),
    // Never optimistic: this is what somebody pays for.
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: downloadKeys.studioDetail(id) });
      void queryClient.invalidateQueries({ queryKey: downloadKeys.studioLists() });
    },
  });
}

export function useSetDownloadPrice(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: { currency: string; amount_minor: number }) =>
      apiPut<unknown>(`/studio/downloads/${id}/price`, payload),
    onSuccess: () => {
      // The checklist reads the price, so the detail has to come back too.
      void queryClient.invalidateQueries({ queryKey: downloadKeys.studioDetail(id) });
    },
  });
}

export function useChangeDownloadStatus(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (action: 'publish' | 'unpublish' | 'archive') =>
      apiPost<Download>(`/studio/downloads/${id}/${action}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: downloadKeys.all });
    },
  });
}

export function useDeleteDownload() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => apiDelete<void>(`/studio/downloads/${id}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: downloadKeys.all });
    },
  });
}
