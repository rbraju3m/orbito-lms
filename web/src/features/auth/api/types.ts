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
  /** Present only for token clients (mobile), never for the cookie SPA. */
  token?: string;
}
