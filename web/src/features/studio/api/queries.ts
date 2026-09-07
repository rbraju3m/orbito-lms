import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { catalogKeys, studioKeys } from '@/features/catalog/api/keys';
import type {
  Course,
  CourseListItem,
  CourseSettings,
  CourseStatus,
} from '@/features/catalog/api/types';
import { apiDelete, apiGet, apiGetRaw, apiPatch, apiPost } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

export interface StudioFilters {
  status?: CourseStatus | '';
  q?: string;
  page?: number;
}

export const studioCoursesQuery = (filters: StudioFilters) =>
  queryOptions({
    queryKey: studioKeys.list(filters),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<CourseListItem>>('/studio/courses', { signal, params: filters }),
    staleTime: 30_000,
  });

export const studioCourseQuery = (id: string) =>
  queryOptions({
    queryKey: studioKeys.detail(id),
    queryFn: ({ signal }) => apiGet<Course>(`/studio/courses/${id}`, { signal }),
    staleTime: 30_000,
  });

export interface CreateCoursePayload {
  title: string;
  subtitle?: string;
  category_id?: number | null;
}

export function useCreateCourse() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: CreateCoursePayload) => apiPost<Course>('/studio/courses', payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: studioKeys.lists() });
    },
  });
}

export interface UpdateCoursePayload {
  title?: string;
  subtitle?: string | null;
  description?: string | null;
  category_id?: number | null;
  level?: string;
  locale?: string;
  visibility?: string;
  completion_mode?: string;
  thumbnail_media_id?: number | null;
  tags?: string[];
  detail?: Record<string, string[]>;
}

export function useUpdateCourse(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: UpdateCoursePayload) =>
      apiPatch<Course>(`/studio/courses/${id}`, payload),
    onSuccess: (course) => {
      queryClient.setQueryData(studioKeys.detail(id), course);
      void queryClient.invalidateQueries({ queryKey: studioKeys.lists() });
      // The public course page may now be stale too.
      void queryClient.invalidateQueries({ queryKey: catalogKeys.lists() });
    },
  });
}

export function useUpdateCourseSettings(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: Partial<CourseSettings>) =>
      apiPatch<CourseSettings>(`/studio/courses/${id}/settings`, payload),
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: studioKeys.detail(id) });
    },
  });
}

export type CourseTransition =
  'publish' | 'unpublish' | 'submit-review' | 'approve-review' | 'reject-review' | 'archive';

/**
 * Publishing is never optimistic: the server runs the publish checklist and may
 * refuse. Showing "Published" and then taking it back would be a lie.
 */
export function useCourseTransition(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ transition, note }: { transition: CourseTransition; note?: string }) =>
      apiPost<Course>(`/studio/courses/${id}/${transition}`, note ? { note } : undefined),
    onSuccess: (course) => {
      queryClient.setQueryData(studioKeys.detail(id), course);
      void queryClient.invalidateQueries({ queryKey: studioKeys.lists() });
      void queryClient.invalidateQueries({ queryKey: catalogKeys.lists() });
    },
  });
}

export function useDeleteCourse() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => apiDelete(`/studio/courses/${id}`),
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: studioKeys.lists() });
    },
  });
}
