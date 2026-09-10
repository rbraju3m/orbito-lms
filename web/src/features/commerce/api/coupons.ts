import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { apiDelete, apiGetRaw, apiPost, apiPut } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

import { commerceKeys } from './queries';
import type { Cart } from './types';

export type DiscountType = 'percent' | 'fixed';
export type CouponState = 'active' | 'scheduled' | 'expired' | 'used_up' | 'off';

export interface CouponProduct {
  id: string;
  title: string;
  type: string;
}

export interface Coupon {
  id: string;
  code: string;
  description: string | null;
  discount_type: DiscountType;
  percent_off: number | null;
  amount_off_minor: number | null;
  currency: string | null;
  applies_to_all: boolean;
  products?: CouponProduct[];
  min_subtotal_minor: number | null;
  max_redemptions: number | null;
  max_redemptions_per_user: number | null;
  starts_at: string | null;
  ends_at: string | null;
  is_active: boolean;
  /** Derived by the server from the switch, the dates and PAID uses. */
  state: CouponState;
  state_label: string;
  /** Paid orders only — an unpaid checkout is not a sale. */
  times_used: number;
  created_at: string;
}

/** The WHOLE coupon: saving replaces its definition (see SaveCoupon). */
export interface CouponInput {
  code: string;
  description: string | null;
  discount_type: DiscountType;
  percent_off: number | null;
  amount_off_minor: number | null;
  currency: string | null;
  applies_to_all: boolean;
  product_ids: string[];
  min_subtotal_minor: number | null;
  max_redemptions: number | null;
  max_redemptions_per_user: number | null;
  starts_at: string | null;
  ends_at: string | null;
  is_active: boolean;
}

export const couponKeys = {
  all: ['coupons'] as const,
  lists: () => [...couponKeys.all, 'list'] as const,
  list: (page: number) => [...couponKeys.lists(), page] as const,
  products: (q: string) => [...couponKeys.all, 'products', q] as const,
};

export const couponsQuery = (page: number) =>
  queryOptions({
    queryKey: couponKeys.list(page),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<Coupon>>('/admin/coupons', { signal, params: { page } }),
    staleTime: 30_000,
  });

/** What a coupon can be scoped to: everything for sale, searched on the server. */
export const couponProductsQuery = (q: string) =>
  queryOptions({
    queryKey: couponKeys.products(q),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<CouponProduct>>('/admin/coupons/products', {
        signal,
        params: { q: q || undefined, per_page: 100 },
      }),
    staleTime: 60_000,
  });

export function useSaveCoupon(id: string | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: CouponInput) =>
      id === null
        ? apiPost<Coupon>('/admin/coupons', input)
        : apiPut<Coupon>(`/admin/coupons/${id}`, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: couponKeys.lists() });
    },
  });
}

/** 409 `coupon_in_use` once it is on an order — the caller shows the reason. */
export function useDeleteCoupon() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => apiDelete(`/admin/coupons/${id}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: couponKeys.lists() });
    },
  });
}

/*
 * The learner's side. Not optimistic: whether a code applies is the server's
 * answer — dates, limits, scope, a minimum spend — and a discount the page
 * showed and then took back is worse than a moment's wait (CLAUDE.md §5).
 */

export function useApplyCoupon() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (code: string) => apiPost<Cart>('/cart/coupon', { code }),
    onSuccess: (cart) => {
      queryClient.setQueryData(commerceKeys.cart(), cart);
    },
  });
}

export function useRemoveCoupon() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => apiDelete<Cart>('/cart/coupon'),
    onSuccess: (cart) => {
      queryClient.setQueryData(commerceKeys.cart(), cart);
    },
  });
}
