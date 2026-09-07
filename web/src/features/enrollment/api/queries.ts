import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { studioKeys } from '@/features/catalog/api/keys';
import { apiGetRaw, apiPatch, apiPost, apiPut } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

import type {
  BulkEnrollResult,
  CoursePrerequisite,
  CourseStudent,
  Enrollment,
  EnrollmentAction,
  RosterFilters,
} from './types';

export const enrollmentKeys = {
  all: ['enrollment'] as const,
  roster: (courseId: string, filters: RosterFilters) =>
    [...enrollmentKeys.all, 'roster', courseId, filters] as const,
  /** Prefix for invalidation: every page and filter of one course's roster. */
  rosterFor: (courseId: string) => [...enrollmentKeys.all, 'roster', courseId] as const,
};

export const rosterQuery = (courseId: string, filters: RosterFilters) =>
  queryOptions({
    queryKey: enrollmentKeys.roster(courseId, filters),
    // Raw, not apiGet: a paginated response keeps its envelope, because the
    // caller needs `meta` for the pager.
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<CourseStudent>>(`/studio/courses/${courseId}/students`, {
        signal,
        params: {
          page: filters.page,
          ...(filters.status !== 'all' ? { status: filters.status } : {}),
          ...(filters.search ? { search: filters.search } : {}),
        },
      }),
    staleTime: 15_000,
  });

/**
 * Granting a seat. Never optimistic: the server decides whether there is one
 * left, whether the address matches an account, and whether the cap allows it
 * — guessing any of that and rolling back would show a student who was never
 * enrolled.
 */
export function useEnrolStudent(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: { email?: string; user_id?: string; expires_at?: string }) =>
      apiPost<CourseStudent>(`/studio/courses/${courseId}/enrollments`, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: enrollmentKeys.rosterFor(courseId) });
    },
  });
}

export function useBulkEnrol(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (emails: string[]) =>
      apiPost<BulkEnrollResult>(`/studio/courses/${courseId}/enrollments/bulk`, { emails }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: enrollmentKeys.rosterFor(courseId) });
    },
  });
}

/**
 * Suspend, reinstate, extend or revoke.
 *
 * Not optimistic either. These change what a learner can reach, and a row that
 * flickers to "suspended" and back because the server refused is worse than a
 * row that takes a moment to settle.
 */
export function useChangeEnrollment(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: {
      enrollmentId: string;
      action: EnrollmentAction;
      reason?: string;
      expires_at?: string | null;
    }) =>
      apiPatch<Enrollment>(`/studio/enrollments/${input.enrollmentId}`, {
        action: input.action,
        ...(input.reason !== undefined ? { reason: input.reason } : {}),
        ...(input.action === 'extend' ? { expires_at: input.expires_at ?? null } : {}),
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: enrollmentKeys.rosterFor(courseId) });
    },
  });
}

/**
 * Whole set, never a delta — two authors editing must not interleave into a
 * set neither of them asked for.
 *
 * There is no GET for prerequisites: they arrive inside the course payload,
 * so the course detail is what gets invalidated rather than a list of its own.
 */
export function useSetPrerequisites(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (courseIds: number[]) =>
      apiPut<CoursePrerequisite[]>(`/studio/courses/${courseId}/prerequisites`, {
        course_ids: courseIds,
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: studioKeys.detail(courseId) });
    },
  });
}
