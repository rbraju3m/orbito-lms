import { keepPreviousData, queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { apiGetRaw, apiPost } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

/** The academy's invitations. Wire contract: docs/INVITATIONS.md §4. */

export type InvitationRole = 'student' | 'instructor';
export type InvitationStatus = 'pending' | 'expired' | 'accepted' | 'revoked';

export interface Invitation {
  id: string;
  email: string;
  role: InvitationRole;
  role_label: string;
  /** Derived by the server from the clock — never computed here. */
  status: InvitationStatus;
  status_label: string;
  sent_count: number;
  last_sent_at: string;
  expires_at: string;
  accepted_at: string | null;
  revoked_at: string | null;
  created_at: string;
  /** From the same rule the endpoints enforce, so no button can 409. */
  can_resend: boolean;
  can_revoke: boolean;
}

export interface InvitationFilters {
  page: number;
  status: InvitationStatus | 'all';
  q: string;
}

export const invitationKeys = {
  all: ['invitations'] as const,
  lists: () => [...invitationKeys.all, 'list'] as const,
  list: (filters: InvitationFilters) => [...invitationKeys.lists(), filters] as const,
};

export const invitationsQuery = (filters: InvitationFilters) =>
  queryOptions({
    queryKey: invitationKeys.list(filters),
    queryFn: ({ signal }) => {
      const params: Record<string, string | number> = { page: filters.page };
      if (filters.status !== 'all') params['status'] = filters.status;
      if (filters.q.trim() !== '') params['q'] = filters.q.trim();

      return apiGetRaw<Paginated<Invitation>>('/admin/invitations', { signal, params });
    },
    staleTime: 30_000,
    // Typing in the search box must not blank the list between keystrokes.
    placeholderData: keepPreviousData,
  });

/** Not optimistic: each of these sends a mail or changes who may join. */
function useInvitationMutation<TInput>(request: (input: TInput) => Promise<Invitation>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: request,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: invitationKeys.lists() });
    },
  });
}

export function useSendInvitation() {
  return useInvitationMutation((input: { email: string; role: InvitationRole }) =>
    apiPost<Invitation>('/admin/invitations', input),
  );
}

export function useResendInvitation() {
  return useInvitationMutation((id: string) =>
    apiPost<Invitation>(`/admin/invitations/${id}/resend`),
  );
}

export function useRevokeInvitation() {
  return useInvitationMutation((id: string) =>
    apiPost<Invitation>(`/admin/invitations/${id}/revoke`),
  );
}
