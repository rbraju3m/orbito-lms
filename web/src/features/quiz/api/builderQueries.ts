import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { apiDelete, apiGet, apiPatch, apiPost } from '@/shared/api/client';

import type {
  AuthoredQuestion,
  QuestionDraft,
  QuizBuilderState,
  QuizSettings,
} from './builderTypes';

export const quizBuilderKeys = {
  all: ['quiz-builder'] as const,
  detail: (itemId: string) => [...quizBuilderKeys.all, itemId] as const,
};

export const quizBuilderQuery = (itemId: string) =>
  queryOptions({
    queryKey: quizBuilderKeys.detail(itemId),
    queryFn: ({ signal }) => apiGet<QuizBuilderState>(`/studio/items/${itemId}/quiz`, { signal }),
    staleTime: 15_000,
  });

export function useUpdateQuizSettings(itemId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (patch: Partial<QuizSettings>) =>
      apiPatch<QuizSettings>(`/studio/items/${itemId}/quiz`, patch),
    onSuccess: (settings) => {
      queryClient.setQueryData(
        quizBuilderKeys.detail(itemId),
        (previous: QuizBuilderState | undefined) =>
          previous ? { ...previous, settings } : previous,
      );
    },
  });
}

export function useSaveQuestion(itemId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    // One mutation for both create and update: the form is identical, and the
    // only difference is whether the question already has an id.
    mutationFn: ({ id, draft }: { id?: string; draft: QuestionDraft }) =>
      id === undefined
        ? apiPost<AuthoredQuestion>(`/studio/items/${itemId}/quiz/questions`, draft)
        : apiPatch<AuthoredQuestion>(`/studio/items/${itemId}/quiz/questions/${id}`, draft),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: quizBuilderKeys.detail(itemId) });
    },
  });
}

export function useDeleteQuestion(itemId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => apiDelete(`/studio/items/${itemId}/quiz/questions/${id}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: quizBuilderKeys.detail(itemId) });
    },
  });
}

export function useReorderQuestions(itemId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (questionIds: string[]) =>
      apiPatch<AuthoredQuestion[]>(`/studio/items/${itemId}/quiz/questions/order`, {
        question_ids: questionIds,
      }),
    // Optimistic: the list is already in the new order on screen, and putting
    // it back on failure is what the user expects to see.
    onMutate: async (questionIds) => {
      await queryClient.cancelQueries({ queryKey: quizBuilderKeys.detail(itemId) });
      const previous = queryClient.getQueryData<QuizBuilderState>(quizBuilderKeys.detail(itemId));

      if (previous) {
        const byId = new Map(previous.questions.map((question) => [question.id, question]));
        queryClient.setQueryData(quizBuilderKeys.detail(itemId), {
          ...previous,
          questions: questionIds
            .map((id) => byId.get(id))
            .filter((question): question is AuthoredQuestion => question !== undefined),
        });
      }

      return { previous };
    },
    onError: (_error, _ids, context) => {
      if (context?.previous) {
        queryClient.setQueryData(quizBuilderKeys.detail(itemId), context.previous);
      }
    },
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: quizBuilderKeys.detail(itemId) });
    },
  });
}
