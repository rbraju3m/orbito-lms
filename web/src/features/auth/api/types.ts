export type UserStatus = 'active' | 'pending' | 'suspended';

export interface InstructorProfile {
  id: number;
  status: 'pending' | 'approved' | 'rejected' | 'blocked';
  status_label: string;
  applied_at: string | null;
  reviewed_at: string | null;
  rating_avg: number;
  rating_count: number;
  course_count: number;
  student_count: number;
  review_note?: string | null;
  application_message?: string | null;
}

export interface User {
  id: string;
  name: string;
  email?: string;
  phone?: string | null;
  headline: string | null;
  bio: string | null;
  timezone: string;
  locale: string;
  status: UserStatus;
  email_verified: boolean;
  created_at: string | null;
  last_login_at?: string | null;
  social_links?: Record<string, string>;
  instructor_profile?: InstructorProfile | null;
}

/** The GET /auth/me payload. */
export interface Session {
  user: User;
  roles: string[];
  permissions: string[];
  is_instructor: boolean;
  must_verify_email: boolean;
  /**
   * The platform operator flag — the academy REGISTRY, not a role. An academy
   * Super Admin is a different thing and appears in `roles`.
   */
  is_platform_operator: boolean;
  /** The one permanent account. Cannot be deleted, suspended or demoted. */
  is_platform_owner: boolean;
  /**
   * The academy the caller is inside. Always set for a member; null for an
   * operator who has entered none, and then the product screens have no data
   * behind them.
   */
  academy: SessionAcademy | null;
  /** Present only for token clients (mobile), never for the cookie SPA. */
  token?: string;
}

export interface SessionAcademy {
  id: string;
  slug: string;
  name: string;
}
