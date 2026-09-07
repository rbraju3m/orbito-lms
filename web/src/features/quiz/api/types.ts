export type QuestionType =
  | 'single_choice'
  | 'multiple_choice'
  | 'true_false'
  | 'short_answer'
  | 'long_answer'
  | 'fill_blank'
  | 'matching'
  | 'ordering'
  | 'image_choice'
  | 'image_matching';

export type AttemptStatus = 'in_progress' | 'awaiting_review' | 'graded' | 'abandoned' | 'expired';

export interface QuestionOption {
  id: number;
  label: string;
  media_url: string | null;
}

/**
 * A question as the learner receives it.
 *
 * Note what is NOT here: is_correct, match_key, explanation, accepted answers.
 * The server serves a different resource during an attempt (ADR-06), and this
 * type is the client-side mirror of that guarantee.
 */
export interface AttemptQuestion {
  id: string;
  ref: number;
  type: QuestionType;
  type_label: string;
  title: string;
  body: string | null;
  points: number;
  media_url: string | null;
  blank_count?: number;
  match_targets?: string[];
  options?: QuestionOption[];
}

export interface Attempt {
  id: string;
  attempt_number: number;
  status: AttemptStatus;
  status_label: string;
  started_at: string;
  expires_at: string | null;
  /** Derived from the server's deadline. The client clock is display only. */
  seconds_remaining: number | null;
  submitted_at: string | null;
  total_points?: number;
  earned_points?: number;
  percent?: number;
  result?: string | null;
  passed?: boolean;
  quiz?: {
    passing_score_percent: number;
    feedback_mode: string;
    questions_per_page: number;
    hide_question_numbers: boolean;
    allow_previous_button: boolean;
    show_correct_answers: boolean;
  };
}

export type AnswerPayload =
  | { option_id: number }
  | { option_ids: number[] }
  | { text: string }
  | { blanks: string[] }
  | { pairs: Record<string, string> }
  | Record<string, never>;

export interface RunnerState {
  attempt: Attempt;
  questions: AttemptQuestion[];
  answers: Record<string, AnswerPayload>;
}

export interface ReviewRow {
  question_id: string;
  type: QuestionType;
  title: string;
  points_possible: number;
  points_earned: number;
  is_correct: boolean | null;
  awaiting_review: boolean;
  your_answer: AnswerPayload | null;
  /** The same answer rendered with option labels rather than raw ids. */
  your_answer_label: string | string[] | Record<string, string> | null;
  feedback: string | null;
  explanation?: string | null;
  correct_answer?: unknown;
}

export interface AttemptResult {
  attempt: Attempt;
  review: ReviewRow[];
}

export interface AttemptHistory {
  attempts: Attempt[];
  attempts_allowed: number | null;
  attempts_used: number;
}
