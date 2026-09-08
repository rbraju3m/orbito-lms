import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { apiDelete, apiGet, apiGetRaw, apiPatch, apiPost } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

import type { CalendarResponse, Cohort, LiveSession, Roster, Webinar } from './types';

export const liveKeys = {
  all: ['live'] as const,
  calendar: (from: string, to: string) => [...liveKeys.all, 'calendar', from, to] as const,
  sessions: (courseId: string) => [...liveKeys.all, 'sessions', courseId] as const,
  cohorts: (courseId: string) => [...liveKeys.all, 'cohorts', courseId] as const,
  roster: (sessionId: string) => [...liveKeys.all, 'roster', sessionId] as const,
  webinars: () => [...liveKeys.all, 'webinars'] as const,
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

export const courseSessionsQuery = (courseId: string) =>
  queryOptions({
    queryKey: liveKeys.sessions(courseId),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<LiveSession>>(`/courses/${courseId}/live-sessions`, { signal }),
    staleTime: 60_000,
    refetchInterval: 60_000,
  });

export const cohortsQuery = (courseId: string) =>
  queryOptions({
    queryKey: liveKeys.cohorts(courseId),
    queryFn: ({ signal }) =>
      apiGetRaw<WithMeta<Cohort, { can_manage: boolean }>>(`/courses/${courseId}/cohorts`, {
        signal,
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
      apiGetRaw<WithMeta<Webinar, { can_manage: boolean }>>('/webinars', { signal }),
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
