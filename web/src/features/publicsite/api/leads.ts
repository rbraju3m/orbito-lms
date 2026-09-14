import { queryOptions, useMutation } from '@tanstack/react-query';

import { apiGet, apiPost, ensureCsrfCookie } from '@/shared/api/client';

import { publicKeys } from './keys';

/** What the server hands a lead form before anybody types into it. docs/LEADS.md. */
export interface LeadForm {
  /** Opaque. Posted back unchanged. */
  token: string;
  /** The words the checkbox asks people to agree to — the server's, stored with the lead. */
  consent_text: string;
}

export type LeadSource = 'site' | 'course' | 'webinar';

export interface LeadInput {
  email: string;
  name: string | null;
  consent: boolean;
  source: LeadSource;
  /** The course or event slug; the server resolves it and ignores anything else. */
  source_slug?: string;
  form_token: string;
  /** The honeypot. Always empty when a person filled the form in. */
  website: string;
}

const base = (academy: string) => `/public/${encodeURIComponent(academy)}`;

/**
 * The form's token, fetched when the form mounts and NEVER refetched behind
 * the reader's back. The server discards a form posted too soon after its
 * token was issued, so a silent refetch on window focus would make a person
 * who came back to the tab and pressed Send look exactly like a script.
 *
 * `gcTime: 0` so the next visit to the page gets a fresh one rather than a
 * token that may have expired while it sat in the cache.
 */
export const publicLeadFormQuery = (academy: string) =>
  queryOptions({
    queryKey: publicKeys.leadForm(academy),
    queryFn: ({ signal }) => apiGet<LeadForm>(`${base(academy)}/lead-form`, { signal }),
    staleTime: Infinity,
    gcTime: 0,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  });

/**
 * Not optimistic, and the answer carries nothing: the server replies the same
 * way whatever it did with the submission, so "thank you" is all the page can
 * truthfully say.
 */
export function useSubmitLead(academy: string) {
  return useMutation({
    mutationFn: async (input: LeadInput) => {
      // A stranger has never signed in, so there is no CSRF cookie yet.
      await ensureCsrfCookie();

      return apiPost<{ received: true }>(`${base(academy)}/leads`, input);
    },
  });
}
