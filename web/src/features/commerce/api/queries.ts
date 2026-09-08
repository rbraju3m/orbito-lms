import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { catalogKeys } from '@/features/catalog/api/keys';
import { apiDelete, apiGet, apiGetRaw, apiPost, apiPut } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

import type { Cart, Order, PaymentGatewayAccount, PaymentHandoff } from './types';

export const commerceKeys = {
  all: ['commerce'] as const,
  cart: () => [...commerceKeys.all, 'cart'] as const,
  orders: () => [...commerceKeys.all, 'orders'] as const,
  orderList: (page: number) => [...commerceKeys.orders(), 'list', page] as const,
  /** Prefix, so invalidating a list does not evict every detail entry. */
  orderLists: () => [...commerceKeys.orders(), 'list'] as const,
  order: (id: string) => [...commerceKeys.orders(), 'detail', id] as const,
  gateways: () => [...commerceKeys.all, 'gateways'] as const,
};

/**
 * The basket is priced LIVE on the server, so it is deliberately not cached
 * for long: a stale total is the one number on this screen that must not be
 * wrong for more than a moment.
 */
export const cartQuery = () =>
  queryOptions({
    queryKey: commerceKeys.cart(),
    queryFn: ({ signal }) => apiGet<Cart>('/cart', { signal }),
    staleTime: 10_000,
  });

export const ordersQuery = (page: number) =>
  queryOptions({
    queryKey: commerceKeys.orderList(page),
    queryFn: ({ signal }) => apiGetRaw<Paginated<Order>>('/orders', { signal, params: { page } }),
    staleTime: 30_000,
  });

export const orderQuery = (id: string) =>
  queryOptions({
    queryKey: commerceKeys.order(id),
    queryFn: ({ signal }) => apiGet<Order>(`/orders/${id}`, { signal }),
    /*
     * Short, because an order that is awaiting payment becomes paid without
     * the browser doing anything — the webhook lands out of band. Anything
     * longer leaves a learner staring at "awaiting payment" after they have
     * paid.
     */
    staleTime: 5_000,
  });

export const paymentGatewaysQuery = () =>
  queryOptions({
    queryKey: commerceKeys.gateways(),
    queryFn: ({ signal }) => apiGet<PaymentGatewayAccount[]>('/admin/payment-gateways', { signal }),
    staleTime: 60_000,
  });

/**
 * Basket mutations.
 *
 * None of them are optimistic, and that is a decision rather than an
 * omission. The server decides whether a product is still sellable, still
 * priced in this currency, and not already owned — three answers the client
 * cannot guess. Rolling back a line the learner watched appear is worse than
 * a moment's latency (CLAUDE.md §5).
 */
export function useAddToCart() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (productId: string) => apiPost<Cart>('/cart/items', { product_id: productId }),
    onSuccess: (cart) => {
      queryClient.setQueryData(commerceKeys.cart(), cart);
    },
  });
}

export function useRemoveCartLine() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (lineId: string) => apiDelete<Cart>(`/cart/items/${lineId}`),
    onSuccess: (cart) => {
      queryClient.setQueryData(commerceKeys.cart(), cart);
    },
  });
}

export function useClearCart() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => apiDelete<Cart>('/cart'),
    onSuccess: (cart) => {
      queryClient.setQueryData(commerceKeys.cart(), cart);
    },
  });
}

/**
 * Checkout consumes the basket and creates an order.
 *
 * Both caches are invalidated because both changed: the basket is now empty,
 * and there is an order that was not there before.
 */
export function useCheckout() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => apiPost<Order>('/checkout'),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: commerceKeys.cart() });
      void queryClient.invalidateQueries({ queryKey: commerceKeys.orderLists() });
    },
  });
}

/**
 * Hands the order to a gateway. Grants NOTHING — the order moves to
 * `awaiting_payment` and the learner is sent to the provider. Access follows a
 * verified webhook the browser never sees (ADR-05).
 */
export function usePayOrder(orderId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (gateway: string) => apiPost<PaymentHandoff>(`/orders/${orderId}/pay`, { gateway }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: commerceKeys.order(orderId) });
      void queryClient.invalidateQueries({ queryKey: commerceKeys.orderLists() });
      // Owning a course changes what the catalogue may sell, and the enrolment
      // gates on a course page are computed server-side.
      void queryClient.invalidateQueries({ queryKey: catalogKeys.all });
    },
  });
}

export interface ConnectGatewayInput {
  credentials?: Record<string, string>;
  webhook_secret?: string;
  is_active?: boolean;
  is_test_mode?: boolean;
}

/**
 * Connecting an academy's own gateway (ADR-13).
 *
 * A partial update: fields left out KEEP their stored value, so toggling test
 * mode does not blank a secret. The form relies on that rather than round-
 * tripping credentials it is never allowed to read back.
 */
export function useConnectGateway() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ gateway, ...input }: ConnectGatewayInput & { gateway: string }) =>
      apiPut<PaymentGatewayAccount>(`/admin/payment-gateways/${gateway}`, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: commerceKeys.gateways() });
    },
  });
}

export function useDisconnectGateway() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (gateway: string) => apiDelete(`/admin/payment-gateways/${gateway}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: commerceKeys.gateways() });
    },
  });
}
