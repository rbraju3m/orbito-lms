import { useMutation, useQueryClient } from '@tanstack/react-query';

import { apiPost } from '@/shared/api/client';

import { commerceKeys } from './queries';
import type { Refund } from './types';

export type RefundMethod = 'gateway' | 'external';

export interface RefundInput {
  amount_minor: number;
  method: RefundMethod;
  reason: string | null;
  /** Takes effect only if this refund empties the order (the server decides). */
  revoke_access: boolean;
}

/**
 * Gives money back. Never optimistic — this moves money (CLAUDE.md §5).
 *
 * The order is refetched whether it worked or not: a refund the provider
 * declined is still recorded, as `failed`, and belongs in the list.
 */
export function useRefundOrder(orderId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: RefundInput) => apiPost<Refund>(`/admin/orders/${orderId}/refunds`, input),
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: commerceKeys.order(orderId) });
      void queryClient.invalidateQueries({ queryKey: commerceKeys.orderLists() });
    },
  });
}
