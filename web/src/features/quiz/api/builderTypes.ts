import type { QuestionType } from './types';

/**
 * The AUTHORING shapes. These carry correct answers, and are only ever fetched
 * from /studio routes — the runner uses `types.ts`, which deliberately cannot
 * represent them (ADR-06).
 */

export type TimeExpiryPolicy = 'auto_submit' | 'auto_abandon';
export type GradingPolicy = 'highest' | 'latest' | 'average' | 'first';
export type FeedbackMode = 'default' | 'reveal' | 'retry';
export type ShowAnswersAfter = 'never' | 'submission' | 'attempts_exhausted';

export interface QuizSettings {
  description: string | null;
  instructions: string | null;
  time_limit_seconds: number | null;
  time_expiry_policy: TimeExpiryPolicy;
  attempts_allowed: number | null;
  passing_score_percent: number;
  grading_policy: GradingPolicy;
  question_order: 'sorted' | 'random';
  shuffle_answers: boolean;
  questions_per_attempt: number | null;
  questions_per_page: number;
  hide_question_numbers: boolean;
  feedback_mode: FeedbackMode;
  show_correct_answers_after: ShowAnswersAfter;
  negative_marking: boolean;
  allow_previous_button: boolean;
}

export interface AuthoredOption {
  id?: number;
  label: string;
  media_id?: number | null;
  is_correct?: boolean;
  match_key?: string | null;
  position?: number;
}

export interface QuestionSettings {
  accepted?: string[];
  case_sensitive?: boolean;
  blanks?: Array<{ accepted: string[] }>;
}

export interface AuthoredQuestion {
  id: string;
  ref: number;
  type: QuestionType;
  type_label: string;
  title: string;
  body: string | null;
  explanation: string | null;
  points: number;
  negative_points: number;
  settings: QuestionSettings | null;
  needs_manual_grading: boolean;
  options?: AuthoredOption[];
}

/** What the form sends. `id` is absent when creating. */
export interface QuestionDraft {
  type: QuestionType;
  title: string;
  body?: string | null;
  explanation?: string | null;
  points: number;
  negative_points?: number;
  settings?: QuestionSettings;
  options?: AuthoredOption[];
}

export interface QuizBuilderState {
  settings: QuizSettings;
  questions: AuthoredQuestion[];
}
