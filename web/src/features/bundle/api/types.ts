import type { CourseListItem, CoursePrice } from '@/features/catalog/api/types';

export type BundleStatus = 'draft' | 'published' | 'archived';

export interface BundleListItem {
  id: string;
  slug: string;
  title: string;
  subtitle: string | null;
  status: BundleStatus;
  status_label: string;
  published_at: string | null;
  /** Present only where the query counted it. */
  course_count?: number;
  thumbnail_url?: string | null;
  /**
   * For DISPLAY. Null means "not buyable right now" — a draft bundle's
   * product is inactive. The figure that CHARGES is re-read at checkout.
   */
  price?: CoursePrice | null;
}

/** One checklist row, rendered from the same rules the server enforces. */
export interface BundleCheck {
  code: string;
  field: string;
  message: string;
  blocking: boolean;
  passed: boolean;
}

export interface Bundle extends BundleListItem {
  description: string | null;
  courses?: CourseListItem[];
  /**
   * What the same courses cost bought separately. Absent when the courses
   * were not loaded — "we did not ask" is not "they are worth nothing".
   */
  parts_total_minor?: number;
  /**
   * Which of them the reader already has. Partial overlap does not block the
   * sale, so the page owes them a plain statement of what is new.
   */
  owned_course_ids?: number[];

  /** Studio only. */
  checklist?: BundleCheck[];
  available_actions?: BundleStatus[];
}

/** What the author sees after setting a price, sale or not. */
export interface StoredPrice {
  currency: string;
  amount_minor: number;
  sale_amount_minor: number | null;
  sale_starts_at: string | null;
  sale_ends_at: string | null;
  is_on_sale: boolean;
  effective_amount_minor: number;
}
