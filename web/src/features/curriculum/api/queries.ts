import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { studioKeys } from '@/features/catalog/api/keys';
import { apiDelete, apiGet, apiPatch, apiPost } from '@/shared/api/client';

import type { CourseItem, CourseSection, ItemType, ReorderPayload } from './types';

export const curriculumKeys = {
  all: ['curriculum'] as const,
  tree: (courseId: string) => [...curriculumKeys.all, courseId] as const,
};

export const curriculumQuery = (courseId: string) =>
  queryOptions({
    queryKey: curriculumKeys.tree(courseId),
    queryFn: ({ signal }) =>
      apiGet<CourseSection[]>(`/studio/courses/${courseId}/curriculum`, { signal }),
    staleTime: 30_000,
  });

/**
 * Reordering is optimistic — it is pure ordering, so a rollback is safe and the
 * drag must feel instant. Publishing and grading are never optimistic.
 */
export function useReorderCurriculum(courseId: string) {
  const queryClient = useQueryClient();
  const key = curriculumKeys.tree(courseId);

  return useMutation({
    mutationFn: (payload: ReorderPayload) =>
      apiPatch<CourseSection[]>(`/studio/courses/${courseId}/curriculum/order`, payload),

    onMutate: async (payload) => {
      await queryClient.cancelQueries({ queryKey: key });
      const previous = queryClient.getQueryData<CourseSection[]>(key);

      if (previous) {
        const byId = new Map(previous.flatMap((s) => s.items).map((item) => [item.ref, item]));

        queryClient.setQueryData<CourseSection[]>(
          key,
          payload.sections.map(({ id, item_ids }) => {
            const section = previous.find((s) => s.id === id);
            return {
              ...(section as CourseSection),
              items: item_ids
                .map((ref) => byId.get(ref))
                .filter((item): item is CourseItem => item !== undefined),
            };
          }),
        );
      }

      return { previous };
    },

    onError: (_error, _payload, context) => {
      // Put the tree back exactly as it was; a half-applied drag is worse than
      // no drag.
      if (context?.previous) queryClient.setQueryData(key, context.previous);
    },

    onSuccess: (tree) => queryClient.setQueryData(key, tree),

    onSettled: () => {
      // Counters on the course (item_count, duration) moved with the tree.
      void queryClient.invalidateQueries({ queryKey: studioKeys.detail(courseId) });
    },
  });
}

function invalidateTree(queryClient: ReturnType<typeof useQueryClient>, courseId: string) {
  void queryClient.invalidateQueries({ queryKey: curriculumKeys.tree(courseId) });
  void queryClient.invalidateQueries({ queryKey: studioKeys.detail(courseId) });
}

export function useCreateSection(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (title: string) =>
      apiPost<CourseSection>(`/studio/courses/${courseId}/sections`, { title }),
    onSettled: () => invalidateTree(queryClient, courseId),
  });
}

export function useUpdateSection(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, title }: { id: number; title: string }) =>
      apiPatch<CourseSection>(`/studio/sections/${id}`, { title }),
    onSettled: () => invalidateTree(queryClient, courseId),
  });
}

export function useDeleteSection(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => apiDelete(`/studio/sections/${id}`),
    onSettled: () => invalidateTree(queryClient, courseId),
  });
}

export function useDuplicateSection(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => apiPost<CourseSection>(`/studio/sections/${id}/duplicate`),
    onSettled: () => invalidateTree(queryClient, courseId),
  });
}

export function useCreateItem(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      sectionId,
      type,
      title,
    }: {
      sectionId: number;
      type: ItemType;
      title: string;
    }) =>
      apiPost<CourseItem>(`/studio/courses/${courseId}/items`, {
        section_id: sectionId,
        type,
        title,
      }),
    onSettled: () => invalidateTree(queryClient, courseId),
  });
}

export interface UpdateItemPayload {
  title?: string;
  is_preview?: boolean;
  is_published?: boolean;
  duration_seconds?: number;

  /** Drip. Stored whatever the course's mode, so switching mode loses nothing. */
  drip_available_at?: string | null;
  drip_after_days?: number | null;
  drip_after_item_id?: number | null;
}

export function useUpdateItem(courseId: string) {
  const queryClient = useQueryClient();
  const key = curriculumKeys.tree(courseId);

  return useMutation({
    mutationFn: ({ id, ...payload }: UpdateItemPayload & { id: string }) =>
      apiPatch<CourseItem>(`/studio/items/${id}`, payload),

    // Inline rename and the preview toggle are safe to show immediately.
    onMutate: async ({ id, ...payload }) => {
      await queryClient.cancelQueries({ queryKey: key });
      const previous = queryClient.getQueryData<CourseSection[]>(key);

      queryClient.setQueryData<CourseSection[]>(key, (sections) =>
        sections?.map((section) => ({
          ...section,
          items: section.items.map((item) => (item.id === id ? { ...item, ...payload } : item)),
        })),
      );

      return { previous };
    },

    onError: (_e, _v, context) => {
      if (context?.previous) queryClient.setQueryData(key, context.previous);
    },

    onSettled: () => invalidateTree(queryClient, courseId),
  });
}

export function useDeleteItem(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => apiDelete(`/studio/items/${id}`),
    onSettled: () => invalidateTree(queryClient, courseId),
  });
}

export function useDuplicateItem(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => apiPost<CourseItem>(`/studio/items/${id}/duplicate`),
    onSettled: () => invalidateTree(queryClient, courseId),
  });
}

export function useUpdateLesson(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, ...payload }: Record<string, unknown> & { id: string }) =>
      apiPatch<CourseItem>(`/studio/items/${id}/lesson`, payload),
    onSettled: () => invalidateTree(queryClient, courseId),
  });
}
