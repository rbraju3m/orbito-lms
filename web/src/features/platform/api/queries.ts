import { queryOptions, useMutation, useQueryClient, type QueryClient } from '@tanstack/react-query';

import { apiGet, apiGetRaw, apiPatch, apiPost, apiPut } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

import type { NewAcademyInput, Plan, Tenant, TenantAction, TenantSubscription } from './types';

export interface TenantFilters {
  page: number;
  status?: string | undefined;
  search?: string | undefined;
}

export const platformKeys = {
  all: ['platform'] as const,
  tenants: (filters: TenantFilters) => [...platformKeys.all, 'tenants', filters] as const,
  tenant: (slug: string) => [...platformKeys.all, 'tenant', slug] as const,
  plans: () => [...platformKeys.all, 'plans'] as const,
};

export const tenantsQuery = (filters: TenantFilters) =>
  queryOptions({
    queryKey: platformKeys.tenants(filters),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<Tenant>>('/admin/tenants', {
        signal,
        params: {
          page: filters.page,
          ...(filters.status ? { status: filters.status } : {}),
          ...(filters.search ? { search: filters.search } : {}),
        },
      }),
  });

export const tenantQuery = (slug: string) =>
  queryOptions({
    queryKey: platformKeys.tenant(slug),
    queryFn: ({ signal }) => apiGet<Tenant>(`/admin/tenants/${slug}`, { signal }),
  });

/**
 * A handful of curated rows, not a growing table — the endpoint returns them
 * all, so this is a plain list rather than a paginated one.
 */
export const plansQuery = () =>
  queryOptions({
    queryKey: platformKeys.plans(),
    queryFn: ({ signal }) => apiGet<Plan[]>('/admin/plans', { signal }),
    // Plans change when an operator edits config, which is not during a
    // session. Refetching them on every screen that offers a picker is waste.
    staleTime: 5 * 60_000,
  });

function invalidateRegistry(queryClient: QueryClient) {
  void queryClient.invalidateQueries({ queryKey: platformKeys.all });
}

export function useProvisionAcademy() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: NewAcademyInput) => apiPost<Tenant>('/admin/tenants', input),
    onSuccess: () => invalidateRegistry(queryClient),
  });
}

/**
 * One mutation for all four transitions: they are four Actions on the server,
 * but one endpoint and one shape here, and four near-identical hooks would
 * drift apart.
 *
 * Never optimistic. Approving an academy provisions nothing but does open it
 * to its members, and showing "active" before the server agreed is the kind of
 * lie that gets acted on.
 */
export function useTransitionAcademy() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      slug,
      action,
      reason,
    }: {
      slug: string;
      action: TenantAction;
      reason?: string | undefined;
    }) => apiPatch<Tenant>(`/admin/tenants/${slug}`, { action, ...(reason ? { reason } : {}) }),
    onSuccess: () => invalidateRegistry(queryClient),
  });
}

export function useAssignPlan() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      slug,
      plan,
      periodEndsAt,
    }: {
      slug: string;
      plan: string;
      periodEndsAt?: string | null;
    }) =>
      apiPut<TenantSubscription>(`/admin/tenants/${slug}/plan`, {
        plan,
        ...(periodEndsAt ? { period_ends_at: periodEndsAt } : {}),
      }),
    onSuccess: () => invalidateRegistry(queryClient),
  });
}

/**
 * Entering or leaving an academy changes which SCHEMA every other request in
 * the app resolves against, so every cached answer in the client belongs to
 * the academy they just left. Clearing the whole cache is not heavy-handed
 * here — it is the only correct thing to do. Anything short of it leaves a
 * course list from one academy on screen while the server answers for another.
 */
function useAcademySwitch<TInput>(mutationFn: (input: TInput) => Promise<unknown>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: () => {
      queryClient.clear();
    },
  });
}

export function useEnterAcademy() {
  return useAcademySwitch((slug: string) => apiPost<Tenant>(`/admin/tenants/${slug}/enter`));
}

export function useLeaveAcademy() {
  return useAcademySwitch(() => apiPost<{ academy: null }>('/admin/tenants/leave'));
}
