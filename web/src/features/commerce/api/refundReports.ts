import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { apiGetRaw, apiPost } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

/** One refund the provider reported that the books could not take in. */
export interface RefundReportItem {
  reason: string | null;
  /** What happened, in words — the server's, never decided here. */
  reason_label: string;
  /** What the person reading it should check. */
  advice: string | null;
  provider_refund_id: string | null;
  amount_minor: number | null;
  currency: string | null;
  reported_status: 'pending' | 'completed' | 'failed' | null;
  reported_status_label: string | null;
}

/** A webhook event left for a person (docs/REFUNDS.md §6). */
export interface RefundReport {
  id: number;
  gateway: string;
  gateway_label: string;
  event_type: string;
  received_at: string;
  items: RefundReportItem[];
  order: { id: string; number: string } | null;
  resolved_at: string | null;
  resolution_note: string | null;
}

export const refundReportKeys = {
  all: ['refund-reports'] as const,
  lists: () => [...refundReportKeys.all, 'list'] as const,
  list: (page: number) => [...refundReportKeys.lists(), page] as const,
};

/** Only what is still open: a resolved report leaves the list. */
export const refundReportsQuery = (page: number) =>
  queryOptions({
    queryKey: refundReportKeys.list(page),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<RefundReport>>('/admin/refund-reports', { signal, params: { page } }),
    staleTime: 30_000,
  });

/**
 * Not optimistic: resolving records who looked and what they found, and the
 * first resolution stands on the server — the list re-reads rather than
 * guessing which one won.
 */
export function useResolveRefundReport() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, note }: { id: number; note: string | null }) =>
      apiPost<RefundReport>(`/admin/refund-reports/${id}/resolve`, { note }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: refundReportKeys.lists() }),
  });
}
