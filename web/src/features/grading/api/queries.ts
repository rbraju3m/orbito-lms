import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import type { Submission } from '@/features/assignment/api/types';
import { apiGet, apiGetRaw, apiPost } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

import type { AttemptGradingView, GradingQueueRow, SubmissionGradingView } from './types';

export const gradingKeys = {
  all: ['grading'] as const,
  queue: (courseId: string, status: string, page: number) =>
    [...gradingKeys.all, 'queue', courseId, status, page] as const,
  attempt: (attemptId: string) => [...gradingKeys.all, 'attempt', attemptId] as const,
  submission: (submissionId: string) => [...gradingKeys.all, 'submission', submissionId] as const,
};

export const gradingQueueQuery = (courseId: string, status: string, page: number) =>
  queryOptions({
    queryKey: gradingKeys.queue(courseId, status, page),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<GradingQueueRow>>(`/studio/courses/${courseId}/grading`, {
        signal,
        params: { status, page },
      }),
    staleTime: 10_000,
  });

export const attemptGradingQuery = (attemptId: string) =>
  queryOptions({
    queryKey: gradingKeys.attempt(attemptId),
    queryFn: ({ signal }) =>
      apiGet<AttemptGradingView>(`/studio/grading/quiz/${attemptId}`, { signal }),
  });

export const submissionGradingQuery = (submissionId: string) =>
  queryOptions({
    queryKey: gradingKeys.submission(submissionId),
    queryFn: ({ signal }) =>
      apiGet<SubmissionGradingView>(`/studio/grading/assignment/${submissionId}`, { signal }),
  });

export interface AnswerGrade {
  question_id: string;
  points: number;
  feedback?: string | null;
}

export function useGradeAttempt(attemptId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (grades: AnswerGrade[]) => apiPost(`/studio/grading/quiz/${attemptId}`, { grades }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: gradingKeys.all });
    },
  });
}

export function useGradeSubmission(submissionId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: { points: number; feedback?: string | null }) =>
      apiPost<Submission>(`/studio/grading/assignment/${submissionId}`, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: gradingKeys.all });
    },
  });
}

export function useReturnSubmission(submissionId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (feedback: string) =>
      apiPost<Submission>(`/studio/grading/assignment/${submissionId}/return`, { feedback }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: gradingKeys.all });
    },
  });
}
