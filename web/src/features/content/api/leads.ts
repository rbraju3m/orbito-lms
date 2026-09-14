import { keepPreviousData, queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { apiDelete, apiGetRaw, apiPatch } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

/** The academy's side of the lead form. Wire contract: docs/API.md, § Leads. */

export type LeadStatus = 'new' | 'contacted' | 'archived';
export type LeadSource = 'site' | 'course' | 'webinar';

export interface Lead {
  id: string;
  email: string;
  name: string | null;
  status: LeadStatus;
  status_label: string;
  source: LeadSource;
  source_label: string;
  /** The course or event title when they asked; null for the front page. */
  source_title: string | null;
  /** The words they agreed to, as the form showed them. */
  consent_text: string;
  consented_at: string;
  submissions_count: number;
  first_submitted_at: string;
  last_submitted_at: string;
}

/** `meta` carries what this reader may do, from the rules the endpoints enforce. */
export interface LeadPage extends Paginated<Lead> {
  meta: Paginated<Lead>['meta'] & { can_manage: boolean; can_export: boolean };
}

export interface LeadFilters {
  page: number;
  status: LeadStatus | 'all';
  q: string;
}

export const leadKeys = {
  all: ['leads'] as const,
  lists: () => [...leadKeys.all, 'list'] as const,
  list: (filters: LeadFilters) => [...leadKeys.lists(), filters] as const,
};

/** The query string the list and the export share, so the file is what the screen showed. */
export function leadParams(filters: Omit<LeadFilters, 'page'>): Record<string, string> {
  const params: Record<string, string> = {};
  if (filters.status !== 'all') params['status'] = filters.status;
  if (filters.q.trim() !== '') params['q'] = filters.q.trim();
  return params;
}

export const leadsQuery = (filters: LeadFilters) =>
  queryOptions({
    queryKey: leadKeys.list(filters),
    queryFn: ({ signal }) =>
      apiGetRaw<LeadPage>('/admin/leads', {
        signal,
        params: { ...leadParams(filters), page: filters.page },
      }),
    staleTime: 30_000,
    // Typing in the search box must not blank the list between keystrokes.
    placeholderData: keepPreviousData,
  });

export function leadsExportPath(filters: Omit<LeadFilters, 'page'>): string {
  const query = new URLSearchParams(leadParams(filters)).toString();
  return query === '' ? '/admin/leads/export' : `/admin/leads/export?${query}`;
}

export function useUpdateLeadStatus() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, status }: { id: string; status: LeadStatus }) =>
      apiPatch<Lead>(`/admin/leads/${id}`, { status }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: leadKeys.lists() });
    },
  });
}

/** Erasure: the row is gone, not hidden. */
export function useDeleteLead() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => apiDelete(`/admin/leads/${id}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: leadKeys.lists() });
    },
  });
}
