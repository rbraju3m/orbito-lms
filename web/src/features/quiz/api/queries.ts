import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { learnKeys } from '@/features/learning/api/queries';
import { apiGet, apiPatch, apiPost } from '@/shared/api/client';

import type { Attempt, AnswerPayload, AttemptHistory, AttemptResult, RunnerState } from './types';

export const quizKeys = {
  all: ['quiz'] as const,
  history: (itemId: string) => [...quizKeys.all, 'history', itemId] as const,
  runner: (attemptId: string) => [...quizKeys.all, 'runner', attemptId] as const,
  result: (attemptId: string) => [...quizKeys.all, 'result', attemptId] as const,
};

export const attemptHistoryQuery = (itemId: string) =>
  queryOptions({
    queryKey: quizKeys.history(itemId),
    queryFn: ({ signal }) =>
      apiGet<AttemptHistory>(`/learn/items/${itemId}/quiz/attempts`, { signal }),
    staleTime: 10_000,
  });

export const runnerQuery = (attemptId: string) =>
  queryOptions({
    queryKey: quizKeys.runner(attemptId),
    queryFn: ({ signal }) => apiGet<RunnerState>(`/learn/quiz-attempts/${attemptId}`, { signal }),
    // Never refetched in the background: a mid-attempt refetch would throw away
    // unsaved local edits and restart the countdown display.
    staleTime: Infinity,
    refetchOnWindowFocus: false,
    retry: false,
  });

export const attemptResultQuery = (attemptId: string) =>
  queryOptions({
    queryKey: quizKeys.result(attemptId),
    queryFn: ({ signal }) =>
      apiGet<AttemptResult>(`/learn/quiz-attempts/${attemptId}/result`, { signal }),
    staleTime: 60_000,
  });

export function useStartAttempt(itemId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => apiPost<RunnerState>(`/learn/items/${itemId}/quiz/attempts`),
    onSuccess: (state) => {
      queryClient.setQueryData(quizKeys.runner(state.attempt.id), state);
      void queryClient.invalidateQueries({ queryKey: quizKeys.history(itemId) });
    },
  });
}

/**
 * Autosave for one answer.
 *
 * Deliberately NOT optimistic in the usual sense: the local draft is already
 * on screen, and the server returns no score, so there is nothing to roll back.
 * What matters is surfacing a failed save rather than losing the answer.
 */
export function useSaveAnswer(attemptId: string) {
  return useMutation({
    mutationFn: ({ questionId, answer }: { questionId: string; answer: AnswerPayload }) =>
      apiPatch<{ saved: boolean; seconds_remaining: number | null }>(
        `/learn/quiz-attempts/${attemptId}/answers`,
        { question_id: questionId, answer },
      ),
    retry: false,
  });
}

export function useSubmitAttempt(attemptId: string, itemId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => apiPost<Attempt>(`/learn/quiz-attempts/${attemptId}/submit`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: quizKeys.history(itemId) });
      void queryClient.invalidateQueries({ queryKey: quizKeys.result(attemptId) });
      // The quiz item is now complete, so the player's progress moved.
      void queryClient.invalidateQueries({ queryKey: learnKeys.all });
    },
  });
}
