import type { CoursePrice } from '@/features/catalog/api/types';

export type DownloadStatus = 'draft' | 'published' | 'archived';
export type DownloadPricing = 'free' | 'one_time';

/** What the buyer is getting — never a link to it. */
export interface DownloadFileSummary {
  name: string;
  mime: string;
  extension: string;
  size_bytes: number;
}

export interface DownloadListItem {
  id: string;
  slug: string;
  title: string;
  subtitle: string | null;
  status: DownloadStatus;
  status_label: string;
  published_at: string | null;
  pricing_model: DownloadPricing;
  is_free: boolean;
  file?: DownloadFileSummary | null;
  thumbnail_url?: string | null;
  /**
   * For DISPLAY. Null for a free download (it has no product) and for one
   * not on sale right now. The figure that CHARGES is re-read at checkout.
   */
  price?: CoursePrice | null;
}

/** One checklist row, from the same rules the server enforces on publish. */
export interface DownloadCheck {
  code: string;
  field: string;
  message: string;
  blocking: boolean;
  passed: boolean;
}

export interface Download extends DownloadListItem {
  description: string | null;
  /**
   * Whether THIS reader may fetch the file right now — from the same class
   * the fetch endpoint asks, so the button and the server agree.
   */
  can_fetch: boolean;

  /** Studio only. */
  checklist?: DownloadCheck[];
  available_actions?: DownloadStatus[];
}

/** A fresh signed link. Lives fifteen minutes; never store it. */
export interface DownloadLink {
  url: string;
  expires_at: string;
}
