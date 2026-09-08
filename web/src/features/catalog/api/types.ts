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
/**
 * What a course costs, for DISPLAY only.
 *
 * The figure that CHARGES is re-read from `product_prices` when the order is
 * placed (ADR-05), so this must never be sent back to the server as an input.
 */
export interface CoursePrice {
  /** The id the basket speaks, so the buy button needs no second request. */
  product_id: string;
  currency: string;
  amount_minor: number;
  /** Present only during a sale, so "was 99" is a fact rather than an inference. */
  list_amount_minor: number | null;
  is_on_sale: boolean;
}

export interface CourseListItem {
  id: string;
  /** The numeric id. Endpoints that REFERENCE a course speak in this. */
  ref: number;
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

  /**
   * What it costs, for DISPLAY. Null means "not buyable right now", which is
   * a different fact from free — a free course has no product at all, while a
   * paid course whose product was deactivated still says `one_time` here.
   * The figure that CHARGES is re-read at checkout (ADR-05).
   */
  price: CoursePrice | null;
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

export type DripMode = 'none' | 'by_date' | 'by_days' | 'sequential';

export interface CoursePrerequisiteSummary {
  id: string;
  ref: number;
  slug: string;
  title: string;
  /** Whether the VIEWER has completed it. */
  is_met: boolean;
}

export interface CourseSettings {
  enable_qa: boolean;
  enable_reviews: boolean;
  enable_notes: boolean;
  enable_certificate: boolean;
  max_students: number | null;
  enrollment_expires_days: number | null;
  /** none | by_date | by_days | sequential — decides which per-item field is read. */
  drip_mode: DripMode;
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
  /** Courses that must be completed before enrolling. Gates entry, not access. */
  prerequisites: CoursePrerequisiteSummary[];
  /** null means uncapped — which is not the same as none left. */
  seats_remaining: number | null;

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
