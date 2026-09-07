export type CourseStatus = 'draft' | 'in_review' | 'published' | 'archived';
export type CourseLevel = 'beginner' | 'intermediate' | 'advanced' | 'all';
export type CourseVisibility = 'public' | 'unlisted' | 'private';
export type PricingModel = 'free' | 'one_time' | 'subscription' | 'mixed';

export interface CourseCategory {
  id: number;
  slug: string;
  name: string;
  description: string | null;
  position: number;
  is_active: boolean;
  children?: CourseCategory[];
  course_count?: number;
}

/** The thin shape used by catalogue and Studio lists. */
export interface CourseListItem {
  id: string;
  slug: string;
  title: string;
  subtitle: string | null;
  level: CourseLevel;
  level_label: string;
  locale: string;
  status: CourseStatus;
  status_label: string;
  visibility: CourseVisibility;
  pricing_model: PricingModel;
  thumbnail_url?: string | null;
  item_count: number;
  total_duration_seconds: number;
  enrollment_count: number;
  rating_avg: number;
  rating_count: number;
  published_at: string | null;
  updated_at: string | null;
  category?: { slug: string; name: string } | null;
  owner?: { id: string; name: string; headline: string | null };
}

export interface PublishCheck {
  code: string;
  field: string;
  message: string;
  blocking: boolean;
  passed: boolean;
}

export interface CourseSettings {
  enable_qa: boolean;
  enable_reviews: boolean;
  enable_notes: boolean;
  enable_certificate: boolean;
  max_students: number | null;
  enrollment_expires_days: number | null;
  drip_mode: string;
  retake_allowed: boolean;
  reset_progress_allowed: boolean;
  video_completion_threshold: number;
}

export interface Course extends Omit<CourseListItem, 'category' | 'thumbnail_url'> {
  description: string | null;
  completion_mode: 'flexible' | 'strict';
  thumbnail?: string | null;
  thumbnail_media_id: number | null;
  intro_video_media_id: number | null;
  intro_video_url: string | null;
  section_count: number;
  created_at: string | null;
  category?: CourseCategory | null;
  tags?: string[];
  detail?: {
    objectives: string[];
    requirements: string[];
    target_audience: string[];
    materials: string[];
  };
  instructors?: Array<{
    id: string;
    name: string;
    headline: string | null;
    role: string;
    role_label: string;
  }>;
  /** Authoring-only fields; absent for a visitor. */
  settings?: CourseSettings;
  publish_checklist?: PublishCheck[];
  allowed_transitions?: CourseStatus[];
}

export interface CatalogFilters {
  q?: string;
  category?: string;
  level?: CourseLevel;
  language?: string;
  price?: 'free' | 'paid';
  sort?: 'popular' | 'newest' | 'rating';
  page?: number;
}
