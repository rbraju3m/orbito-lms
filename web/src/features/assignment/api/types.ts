export type LatePolicy = 'reject' | 'accept' | 'penalise';
export type SubmissionStatus = 'submitted' | 'graded' | 'returned';

export interface AssignmentAttachment {
  id: number;
  name: string;
  size_bytes: number;
  url: string;
}

export interface Assignment {
  instructions: string | null;
  total_points: number;
  passing_points: number | null;
  due_at: string | null;
  late_policy: LatePolicy;
  late_policy_label: string;
  late_penalty_percent: number;
  /** Null means unlimited. */
  max_attempts: number | null;
  allow_text: boolean;
  allow_files: boolean;
  max_file_size_kb: number;
  max_files: number;
  /** Null means "whatever the platform already allows". */
  allowed_extensions: string[] | null;
  attachments?: AssignmentAttachment[];
}

export interface SubmissionFile {
  id: number;
  name: string;
  size_bytes: number;
  url: string | null;
}

export interface Submission {
  id: string;
  attempt_number: number;
  status: SubmissionStatus;
  status_label: string;
  body: string | null;
  submitted_at: string;
  is_late: boolean;
  /**
   * Absent until a grader has been through it. Not marked yet and scoring
   * zero are different things, so the API omits these rather than sending 0.
   */
  points_raw?: number;
  late_penalty_points?: number;
  points_earned?: number;
  passed?: boolean;
  feedback: string | null;
  graded_at: string | null;
  graded_by?: string | null;
  files?: SubmissionFile[];
}

/**
 * The same object the server enforces on submit. Rendering the form from
 * anything else would let the button and the server disagree.
 */
export interface SubmissionRules {
  can_submit: boolean;
  reason: 'no_attempts_left' | 'past_due' | null;
  attempts_used: number;
  attempts_allowed: number | null;
  attempts_left: number | null;
  is_past_due: boolean;
  will_be_late: boolean;
  late_penalty_percent: number;
}

export interface AssignmentBrief {
  assignment: Assignment;
  submissions: Submission[];
  rules: SubmissionRules;
}

/** What the authoring form sends. */
export interface AssignmentDraft {
  instructions?: string | null;
  total_points?: number;
  passing_points?: number | null;
  due_at?: string | null;
  late_policy?: LatePolicy;
  late_penalty_percent?: number;
  max_attempts?: number | null;
  allow_text?: boolean;
  allow_files?: boolean;
  max_file_size_kb?: number;
  max_files?: number;
  allowed_extensions?: string[] | null;
  attachment_media_ids?: number[];
}
