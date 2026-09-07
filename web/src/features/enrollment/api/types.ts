/**
 * The enrollment wire contract. Source of truth: docs/API.md.
 */

export type EnrollmentStatus = 'active' | 'completed' | 'expired' | 'suspended' | 'cancelled';

export type EnrollmentSource =
  'free' | 'purchase' | 'manual' | 'subscription' | 'bundle' | 'membership' | 'import';

export interface Enrollment {
  id: string;
  status: EnrollmentStatus;
  status_label: string;
  source: EnrollmentSource;
  /** Evaluated live — not merely `status === 'active'`. */
  is_active: boolean;
  enrolled_at: string;
  starts_at: string | null;
  expires_at: string | null;
  completed_at: string | null;
  suspended_at: string | null;
  suspended_reason: string | null;
}

export interface CourseProgressSummary {
  completed_items: number;
  total_items: number;
  percent: number;
  is_complete: boolean;
  last_activity_at: string | null;
}

/** One row of the instructor's roster. */
export interface CourseStudent {
  enrollment: Enrollment;
  student: {
    id: string | null;
    name: string | null;
    email: string | null;
  };
  /**
   * Absent, not zero, when nothing has been recorded — "not started" and
   * "scored nothing" are different facts.
   */
  progress?: CourseProgressSummary | null;
}

/** The transitions staff may ask for. Named, never a raw status. */
export type EnrollmentAction = 'suspend' | 'reinstate' | 'extend' | 'revoke';

export interface BulkEnrollRow {
  email: string;
  status: 'enrolled' | 'skipped' | 'not_found';
  message: string | null;
}

export interface BulkEnrollResult {
  results: BulkEnrollRow[];
  summary: {
    enrolled: number;
    skipped: number;
    not_found: number;
  };
}

export interface CoursePrerequisite {
  id: string;
  ref: number;
  slug: string;
  title: string;
  /** Whether the VIEWER has completed it — per-course, so the page can tick. */
  is_met?: boolean;
}

export interface RosterFilters {
  status: string;
  search: string;
  page: number;
}
