import type { Assignment, Submission } from '@/features/assignment/api/types';
import type { ReviewRow } from '@/features/quiz/api/types';

/** One piece of work waiting to be marked, whichever kind it is. */
export interface GradingQueueRow {
  kind: 'quiz' | 'assignment';
  id: string;
  status: string;
  awaiting_review: boolean;
  submitted_at: string | null;
  learner: { id: string | null; name: string | null };
  item: { id: string | null; title: string | null };
}

export interface AttemptGradingView {
  attempt: {
    id: string;
    attempt_number: number;
    status: string;
    status_label: string;
    submitted_at: string | null;
    total_points?: number;
    earned_points?: number;
    percent?: number;
  };
  learner: { id: string | null; name: string | null };
  review: ReviewRow[];
}

export interface SubmissionGradingView {
  submission: Submission;
  assignment: Assignment;
  learner: { id: string | null; name: string | null };
  item: { id: string | null; title: string | null };
}
