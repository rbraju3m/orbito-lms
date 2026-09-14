import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import type { Webinar } from '@/features/live/api/types';
import { apiGet, apiPost, ensureCsrfCookie } from '@/shared/api/client';

import { publicKeys } from './keys';

/**
 * A place at a free webinar for somebody with no account.
 * Wire contract: docs/GUEST_REGISTRATION.md.
 */

/** A guest's place, as the manage page reads it. */
export interface GuestPlace {
  status: 'registered' | 'cancelled';
  email: string;
  name: string | null;
  /** From the rule the cancel endpoint enforces — false for a place that was bought. */
  can_cancel: boolean;
  /** True only while the meeting is open and the place is live. */
  can_join: boolean;
  /** The manage token, echoed so the confirm page can link to the manage page. */
  token: string;
  webinar: Webinar;
}

export interface GuestRegistrationInput {
  email: string;
  name: string | null;
  form_token: string;
  /** The honeypot. Always empty when a person filled the form in. */
  website: string;
}

const base = (academy: string) => `/public/${encodeURIComponent(academy)}`;

/**
 * The form's token. Fetched on mount and never refetched behind the reader's
 * back: the server discards a form posted too soon after its token was issued,
 * so a refetch on focus would make a person look like a script.
 */
export const publicFormTokenQuery = (academy: string) =>
  queryOptions({
    queryKey: publicKeys.formToken(academy),
    queryFn: ({ signal }) => apiGet<{ token: string }>(`${base(academy)}/form-token`, { signal }),
    staleTime: Infinity,
    gcTime: 0,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  });

/**
 * Asking for a place. The answer is the same whatever happened — the server
 * only ever sends a link — so the page can only say "check your inbox".
 */
export function useRequestGuestPlace(academy: string, slug: string) {
  return useMutation({
    mutationFn: async (input: GuestRegistrationInput) => {
      // A stranger has never signed in, so there is no CSRF cookie yet.
      await ensureCsrfCookie();

      return apiPost<{ received: true }>(
        `${base(academy)}/webinars/${encodeURIComponent(slug)}/guest-registrations`,
        input,
      );
    },
  });
}

/**
 * Following the link from the mail. A POST with the token in the body, never
 * a GET: the token is the guest's only credential. Not optimistic — whether
 * the place is still there is the server's answer.
 */
export function useConfirmGuestPlace(academy: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (token: string) => {
      await ensureCsrfCookie();

      return apiPost<GuestPlace>(`${base(academy)}/guest-registrations/confirm`, { token });
    },
    onSuccess: (place) => {
      queryClient.setQueryData(publicKeys.guestPlace(academy, place.token), place);
    },
  });
}

export const guestPlaceQuery = (academy: string, token: string) =>
  queryOptions({
    queryKey: publicKeys.guestPlace(academy, token),
    queryFn: async () => {
      await ensureCsrfCookie();

      return apiPost<GuestPlace>(`${base(academy)}/guest-places/show`, { token });
    },
    staleTime: 30_000,
    // A refused link stays refused; retrying only delays saying so.
    retry: false,
  });

export function useCancelGuestPlace(academy: string, token: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () => {
      await ensureCsrfCookie();

      return apiPost<GuestPlace>(`${base(academy)}/guest-places/cancel`, { token });
    },
    onSuccess: (place) => {
      queryClient.setQueryData(publicKeys.guestPlace(academy, token), place);
    },
  });
}

export function useJoinGuestPlace(academy: string, token: string) {
  return useMutation({
    mutationFn: async () => {
      await ensureCsrfCookie();

      return apiPost<{ join_url: string }>(`${base(academy)}/guest-places/join`, { token });
    },
  });
}
