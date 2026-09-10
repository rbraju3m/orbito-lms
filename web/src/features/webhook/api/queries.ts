import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { apiDelete, apiGet, apiGetRaw, apiPatch, apiPost } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

import type {
  WebhookDelivery,
  WebhookEndpoint,
  WebhookEndpointChanges,
  WebhookEndpointInput,
  WebhookEndpointPage,
  WebhookEndpointWithSecret,
} from './types';

export const webhookKeys = {
  all: ['webhooks'] as const,
  /** Prefix, so creating one invalidates every page without evicting details. */
  lists: () => [...webhookKeys.all, 'list'] as const,
  list: (page: number) => [...webhookKeys.lists(), page] as const,
  detail: (id: string) => [...webhookKeys.all, 'detail', id] as const,
  deliveries: (id: string) => [...webhookKeys.all, 'deliveries', id] as const,
  deliveryPage: (id: string, page: number) => [...webhookKeys.deliveries(id), page] as const,
};

export const webhookEndpointsQuery = (page: number) =>
  queryOptions({
    queryKey: webhookKeys.list(page),
    queryFn: ({ signal }) =>
      apiGetRaw<WebhookEndpointPage>('/admin/webhooks', { signal, params: { page } }),
    staleTime: 30_000,
  });

export const webhookEndpointQuery = (id: string) =>
  queryOptions({
    queryKey: webhookKeys.detail(id),
    queryFn: ({ signal }) => apiGet<WebhookEndpoint>(`/admin/webhooks/${id}`, { signal }),
    staleTime: 30_000,
  });

/**
 * Polls only while a delivery on this page can still change — the same rule
 * as the order page. A pending row settles out of band, in a queue worker;
 * once every row has, there is nothing left to wait for.
 */
export const webhookDeliveriesQuery = (id: string, page: number) =>
  queryOptions({
    queryKey: webhookKeys.deliveryPage(id, page),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<WebhookDelivery>>(`/admin/webhooks/${id}/deliveries`, {
        signal,
        params: { page },
      }),
    refetchInterval: (query) =>
      query.state.data?.data.some((delivery) => delivery.status === 'pending') ? 5_000 : false,
  });

/** The response carries the secret, once. The caller shows it and forgets it. */
export function useCreateWebhookEndpoint() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: WebhookEndpointInput) =>
      apiPost<WebhookEndpointWithSecret>('/admin/webhooks', input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: webhookKeys.lists() });
    },
  });
}

export function useUpdateWebhookEndpoint(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (changes: WebhookEndpointChanges) =>
      apiPatch<WebhookEndpoint>(`/admin/webhooks/${id}`, changes),
    onSuccess: (endpoint) => {
      queryClient.setQueryData(webhookKeys.detail(id), endpoint);
      void queryClient.invalidateQueries({ queryKey: webhookKeys.lists() });
    },
  });
}

export function useDeleteWebhookEndpoint() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => apiDelete(`/admin/webhooks/${id}`),
    onSuccess: (_, id) => {
      queryClient.removeQueries({ queryKey: webhookKeys.detail(id) });
      queryClient.removeQueries({ queryKey: webhookKeys.deliveries(id) });
      void queryClient.invalidateQueries({ queryKey: webhookKeys.lists() });
    },
  });
}

export function useRotateWebhookSecret(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => apiPost<WebhookEndpointWithSecret>(`/admin/webhooks/${id}/rotate-secret`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: webhookKeys.detail(id) });
    },
  });
}

export function useSendTestWebhook(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => apiPost<WebhookDelivery>(`/admin/webhooks/${id}/test`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: webhookKeys.deliveries(id) });
    },
  });
}

export function useRedeliverWebhook(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (deliveryId: string) =>
      apiPost<WebhookDelivery>(`/admin/webhooks/${id}/deliveries/${deliveryId}/redeliver`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: webhookKeys.deliveries(id) });
    },
  });
}
