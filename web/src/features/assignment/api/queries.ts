import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { learnKeys } from '@/features/learning/api/queries';
import { apiGet, apiPatch, apiPost } from '@/shared/api/client';

import type { Assignment, AssignmentBrief, AssignmentDraft, Submission } from './types';

export const assignmentKeys = {
  all: ['assignment'] as const,
  brief: (itemId: string) => [...assignmentKeys.all, 'brief', itemId] as const,
  builder: (itemId: string) => [...assignmentKeys.all, 'builder', itemId] as const,
};

/** The learner's view: the brief, their history, and whether they may submit. */
export const assignmentBriefQuery = (itemId: string) =>
  queryOptions({
    queryKey: assignmentKeys.brief(itemId),
    queryFn: ({ signal }) =>
      apiGet<AssignmentBrief>(`/learn/items/${itemId}/assignment`, { signal }),
    staleTime: 15_000,
  });

export const assignmentBuilderQuery = (itemId: string) =>
  queryOptions({
    queryKey: assignmentKeys.builder(itemId),
    queryFn: ({ signal }) => apiGet<Assignment>(`/studio/items/${itemId}/assignment`, { signal }),
    staleTime: 15_000,
  });

export function useUpdateAssignment(itemId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (draft: AssignmentDraft) =>
      apiPatch<Assignment>(`/studio/items/${itemId}/assignment`, draft),
    onSuccess: (assignment) => {
      queryClient.setQueryData(assignmentKeys.builder(itemId), assignment);
    },
  });
}

export function useSubmitAssignment(itemId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: { body?: string | null; media_ids?: number[] }) =>
      apiPost<Submission>(`/learn/items/${itemId}/assignment/submissions`, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: assignmentKeys.brief(itemId) });
      // Handing work in completes the item, so the player's progress moved.
      void queryClient.invalidateQueries({ queryKey: learnKeys.all });
    },
  });
}
