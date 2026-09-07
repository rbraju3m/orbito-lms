import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { apiDelete, apiGet, apiPost } from '@/shared/api/client';

import type {
  ContinueLearningRow,
  CourseProgress,
  ItemPayload,
  LessonNote,
  PlayerBootstrap,
} from './types';

export const learnKeys = {
  all: ['learn'] as const,
  player: (courseId: string) => [...learnKeys.all, 'player', courseId] as const,
  item: (itemId: string) => [...learnKeys.all, 'item', itemId] as const,
  notes: (itemId: string) => [...learnKeys.all, 'notes', itemId] as const,
  continue: () => [...learnKeys.all, 'continue'] as const,
  myCourses: (filter: string) => [...learnKeys.all, 'my-courses', filter] as const,
};

export const playerQuery = (courseId: string) =>
  queryOptions({
    queryKey: learnKeys.player(courseId),
    queryFn: ({ signal }) => apiGet<PlayerBootstrap>(`/learn/courses/${courseId}`, { signal }),
    staleTime: 30_000,
  });

export const itemQuery = (itemId: string) =>
  queryOptions({
    queryKey: learnKeys.item(itemId),
    queryFn: ({ signal }) => apiGet<ItemPayload>(`/learn/items/${itemId}`, { signal }),
    // A signed video URL is inside this payload and expires, so it must not be
    // served from cache indefinitely.
    staleTime: 5 * 60_000,
    retry: false,
  });

export const notesQuery = (itemId: string) =>
  queryOptions({
    queryKey: learnKeys.notes(itemId),
    queryFn: ({ signal }) => apiGet<LessonNote[]>(`/learn/items/${itemId}/notes`, { signal }),
    staleTime: 30_000,
  });

export const continueLearningQuery = () =>
  queryOptions({
    queryKey: learnKeys.continue(),
    queryFn: ({ signal }) => apiGet<ContinueLearningRow[]>('/learn/continue', { signal }),
    staleTime: 30_000,
  });

/**
 * Marking complete is optimistic — the tick and the progress ring must move
 * the instant the learner clicks, and a rollback is safe.
 */
export function useToggleItemComplete(courseId: string) {
  const queryClient = useQueryClient();
  const key = learnKeys.player(courseId);

  return useMutation({
    mutationFn: ({ itemId, complete }: { itemId: string; complete: boolean }) =>
      complete
        ? apiPost<CourseProgress>(`/learn/items/${itemId}/complete`)
        : apiDelete<CourseProgress>(`/learn/items/${itemId}/complete`),

    onMutate: async ({ itemId, complete }) => {
      await queryClient.cancelQueries({ queryKey: key });
      const previous = queryClient.getQueryData<PlayerBootstrap>(key);

      if (previous) {
        const completedDelta = complete ? 1 : -1;
        const completed = Math.max(
          0,
          Math.min(
            (previous.progress?.completed_items ?? 0) + completedDelta,
            previous.progress?.total_items ?? 0,
          ),
        );
        const total = previous.progress?.total_items ?? 0;

        queryClient.setQueryData<PlayerBootstrap>(key, {
          ...previous,
          progress: previous.progress
            ? {
                ...previous.progress,
                completed_items: completed,
                percent: total === 0 ? 0 : Math.round((completed / total) * 10000) / 100,
              }
            : null,
          curriculum: previous.curriculum.map((section) => ({
            ...section,
            items: section.items.map((item) =>
              item.id === itemId
                ? { ...item, status: complete ? ('completed' as const) : ('in_progress' as const) }
                : item,
            ),
          })),
        });
      }

      return { previous };
    },

    onError: (_e, _v, context) => {
      if (context?.previous) queryClient.setQueryData(key, context.previous);
    },

    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: key });
      void queryClient.invalidateQueries({ queryKey: learnKeys.continue() });
    },
  });
}

/**
 * The video heartbeat. Deliberately fire-and-forget: a dropped position is a
 * few seconds of resume accuracy, not something worth an error state.
 */
export function useRecordWatch() {
  return useMutation({
    mutationFn: ({ itemId, position }: { itemId: string; position: number }) =>
      apiPost(`/learn/items/${itemId}/watch`, { position_seconds: Math.round(position) }),
    retry: false,
  });
}

export function useCompleteCourse(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => apiPost<CourseProgress>(`/learn/courses/${courseId}/complete`),
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: learnKeys.player(courseId) });
      void queryClient.invalidateQueries({ queryKey: learnKeys.continue() });
    },
  });
}

export function useEnroll() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (courseId: string) => apiPost(`/courses/${courseId}/enroll`),
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: learnKeys.all });
    },
  });
}

export function useAddNote(itemId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: { body: string; video_timestamp_seconds?: number | null }) =>
      apiPost<LessonNote>(`/learn/items/${itemId}/notes`, payload),
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: learnKeys.notes(itemId) });
    },
  });
}

export function useDeleteNote(itemId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (noteId: number) => apiDelete(`/learn/notes/${noteId}`),
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: learnKeys.notes(itemId) });
    },
  });
}
