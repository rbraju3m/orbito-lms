import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { apiDelete, apiGet, apiGetRaw, apiPatch, apiPost, apiPut } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

import type {
  CalendarResponse,
  Cohort,
  CohortStatus,
  LiveProviderAccount,
  LiveProviderOption,
  LiveSession,
  Roster,
  Webinar,
} from './types';

export const liveKeys = {
  all: ['live'] as const,
  calendar: (from: string, to: string) => [...liveKeys.all, 'calendar', from, to] as const,
  /** Prefix for every page of a course's sessions. */
  sessions: (courseId: string) => [...liveKeys.all, 'sessions', courseId] as const,
  sessionPage: (courseId: string, page: number) => [...liveKeys.sessions(courseId), page] as const,
  /** Prefix for every page of a course's cohorts. */
  cohorts: (courseId: string) => [...liveKeys.all, 'cohorts', courseId] as const,
  cohortPage: (courseId: string, page: number) => [...liveKeys.cohorts(courseId), page] as const,
  roster: (sessionId: string) => [...liveKeys.all, 'roster', sessionId] as const,
  webinars: () => [...liveKeys.all, 'webinars'] as const,
  /** The academy's connected meeting providers. */
  providers: () => [...liveKeys.all, 'providers'] as const,
};

/** A list that also says what the reader may do with it. */
type WithMeta<T, M> = Paginated<T> & { meta: Paginated<T>['meta'] & M };

export const calendarQuery = (from: string, to: string) =>
  queryOptions({
    queryKey: liveKeys.calendar(from, to),
    queryFn: ({ signal }) =>
      apiGet<CalendarResponse>('/calendar', { signal, params: { from, to } }),
    /*
     * Short, and it matters: `status` is derived from the clock on the server,
     * so a session that becomes joinable while somebody has the page open only
     * shows as such on the next fetch. A minute is the worst case for somebody
     * waiting to join.
     */
    staleTime: 60_000,
    refetchInterval: 60_000,
  });

/**
 * `meta.providers` is the server's answer to "which can I schedule with?",
 * the same one scheduling enforces — empty for anybody who cannot schedule.
 */
export const courseSessionsQuery = (courseId: string, page = 1) =>
  queryOptions({
    queryKey: liveKeys.sessionPage(courseId, page),
    queryFn: ({ signal }) =>
      apiGetRaw<WithMeta<LiveSession, { can_manage: boolean; providers: LiveProviderOption[] }>>(
        `/courses/${courseId}/live-sessions`,
        { signal, params: { page } },
      ),
    staleTime: 60_000,
    refetchInterval: 60_000,
  });

export const cohortsQuery = (courseId: string, page = 1) =>
  queryOptions({
    queryKey: liveKeys.cohortPage(courseId, page),
    queryFn: ({ signal }) =>
      apiGetRaw<WithMeta<Cohort, { can_manage: boolean }>>(`/courses/${courseId}/cohorts`, {
        signal,
        params: { page },
      }),
    staleTime: 60_000,
  });

export const rosterQuery = (sessionId: string) =>
  queryOptions({
    queryKey: liveKeys.roster(sessionId),
    queryFn: ({ signal }) => apiGet<Roster>(`/live-sessions/${sessionId}/attendance`, { signal }),
    staleTime: 30_000,
  });

export const webinarsQuery = () =>
  queryOptions({
    queryKey: liveKeys.webinars(),
    queryFn: ({ signal }) =>
      apiGetRaw<WithMeta<Webinar, { can_manage: boolean; providers: LiveProviderOption[] }>>(
        '/webinars',
        { signal },
      ),
    staleTime: 60_000,
  });

export interface SessionInput {
  title: string;
  description?: string | null;
  provider: 'manual' | 'zoom' | 'google_meet';
  join_url?: string;
  starts_at: string;
  ends_at: string;
  timezone?: string;
  cohort_id?: string;
}

export function useSaveSession(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, ...input }: SessionInput & { id?: string }) =>
      id === undefined
        ? apiPost<LiveSession>(`/courses/${courseId}/live-sessions`, input)
        : apiPatch<LiveSession>(`/live-sessions/${id}`, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: liveKeys.all });
    },
  });
}

export interface CohortInput {
  name: string;
  starts_at: string;
  ends_at: string | null;
  capacity: number | null;
  enrollment_deadline: string | null;
  status: CohortStatus;
  timezone?: string;
}

export function useSaveCohort(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, ...input }: CohortInput & { id?: string }) =>
      id === undefined
        ? apiPost<Cohort>(`/courses/${courseId}/cohorts`, input)
        : apiPatch<Cohort>(`/cohorts/${id}`, input),
    onSuccess: () => {
      // Sessions carry their cohort's name, so both lists re-read.
      void queryClient.invalidateQueries({ queryKey: liveKeys.all });
    },
  });
}

/** Only a run nobody is using — the server answers 409 for any other. */
export function useDeleteCohort() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => apiDelete(`/cohorts/${id}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: liveKeys.all });
    },
  });
}

/** The host's word that somebody was there — a roster entry with source `host`. */
export function useMarkAttendance(sessionId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (userIds: number[]) =>
      apiPost<{ marked: number }>(`/live-sessions/${sessionId}/attendance`, { user_ids: userIds }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: liveKeys.roster(sessionId) });
    },
  });
}

/** Cancelled, never deleted — the attendance and the record of it stay. */
export function useCancelSession() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => apiDelete<LiveSession>(`/live-sessions/${id}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: liveKeys.all });
    },
  });
}

/**
 * Following the link.
 *
 * A mutation even though it feels like a read: the click is what records
 * attendance, because it is the only signal every provider has in common —
 * the manual one reports nothing at all. Fetching the URL and opening it in
 * the same gesture is what keeps the roster honest.
 */
export function useJoinSession() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) =>
      apiPost<{ join_url: string; session: LiveSession }>(`/live-sessions/${id}/join`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: liveKeys.all });
    },
  });
}

export function useJoinCohort() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (cohortId: string) => apiPost(`/cohorts/${cohortId}/join`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: liveKeys.all });
    },
  });
}

export function useWebinarRegistration() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, register }: { id: string; register: boolean }) =>
      register
        ? apiPost<Webinar>(`/webinars/${id}/register`)
        : apiDelete<Webinar>(`/webinars/${id}/register`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: liveKeys.webinars() });
    },
  });
}

/* ------------------------------------------------ connecting a provider */

/**
 * Every provider the platform supports, connected or not — an admin cannot
 * connect Zoom from a list of what is already connected.
 */
export const liveProvidersQuery = () =>
  queryOptions({
    queryKey: liveKeys.providers(),
    queryFn: ({ signal }) => apiGet<LiveProviderAccount[]>('/admin/live-providers', { signal }),
    staleTime: 60_000,
  });

export interface ConnectLiveProviderInput {
  provider: string;
  credentials?: Record<string, string>;
  is_active?: boolean;
}

/**
 * A partial update: what is left out KEEPS its stored value, so switching a
 * provider off does not blank a secret the API will never read back.
 *
 * Invalidates the whole live prefix rather than just this list, because
 * connecting changes what the studio's scheduling picker may offer.
 */
export function useConnectLiveProvider() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ provider, ...input }: ConnectLiveProviderInput) =>
      apiPut<LiveProviderAccount>(`/admin/live-providers/${provider}`, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: liveKeys.all });
    },
  });
}

export function useDisconnectLiveProvider() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (provider: string) => apiDelete(`/admin/live-providers/${provider}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: liveKeys.all });
    },
  });
}

/* --------------------------------------------------- authoring a webinar */

export interface WebinarInput {
  title: string;
  description?: string | null;
  capacity?: number | null;
  /* Whether a place has to be bought. The PRICE is not here — it hangs off a
   * product, which does not exist until the webinar does. */
  is_paid?: boolean;
  /* Only on a create: the session it happens at. A reschedule goes through
   * the session endpoint, which resets the reminder and tells the provider. */
  provider?: 'manual' | 'zoom' | 'google_meet';
  join_url?: string;
  starts_at?: string;
  ends_at?: string;
  timezone?: string;
}

/** Creating one creates its session too; editing one never touches the time. */
export function useSaveWebinar() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, ...input }: WebinarInput & { id?: string }) =>
      id === undefined
        ? apiPost<Webinar>('/webinars', input)
        : apiPatch<Webinar>(`/webinars/${id}`, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: liveKeys.all });
    },
  });
}

/**
 * Publish, unpublish, cancel, revive — one endpoint, because one Action owns
 * the legal moves and `available_actions` says which are open.
 */
export function useChangeWebinarStatus() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, status }: { id: string; status: 'draft' | 'published' | 'cancelled' }) =>
      apiPost<Webinar>(`/webinars/${id}/status`, { status }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: liveKeys.all });
    },
  });
}

/**
 * What a place costs.
 *
 * Its own endpoint, and not part of saving the form, for the same reason a
 * course's and a download's are: a price hangs off a product, and the product
 * does not exist until the webinar does.
 */
export function useSetWebinarPrice(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: { currency: string; amount_minor: number }) =>
      apiPut<unknown>(`/webinars/${id}/price`, payload),
    onSuccess: () => {
      // The publish rule reads the price, so the list has to come back too.
      void queryClient.invalidateQueries({ queryKey: liveKeys.all });
    },
  });
}

export function useDeleteWebinar() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => apiDelete(`/webinars/${id}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: liveKeys.all });
    },
  });
}
