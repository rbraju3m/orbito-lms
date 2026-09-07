export type ItemType = 'lesson' | 'resource' | 'quiz' | 'assignment' | 'live_session';

export type VideoProvider = 'none' | 'upload' | 'youtube' | 'vimeo' | 'external' | 'embed';

export interface LessonContent {
  content: string | null;
  content_format: 'html' | 'markdown';
  video_provider: VideoProvider;
  video_media_id: number | null;
  video_url: string | null;
  video_duration_seconds: number;
  document_media_id: number | null;
}

export interface CourseItem {
  id: string;
  /** Numeric id — the reorder endpoint speaks in these. */
  ref: number;
  section_id: number;
  position: number;
  type: ItemType;
  type_label: string;
  title: string;
  is_preview: boolean;
  is_published: boolean;
  is_completable: boolean;
  duration_seconds: number;
  updated_at: string | null;

  /**
   * Drip parameters. All three are stored regardless of the course's current
   * drip_mode, so switching mode reinterprets what is already there instead of
   * discarding the author's work. `drip_after_item_id` is the numeric ref.
   */
  drip_available_at: string | null;
  drip_after_days: number | null;
  drip_after_item_id: number | null;

  content?: LessonContent | Record<string, unknown> | null;
}

export interface CourseSection {
  id: number;
  title: string;
  description: string | null;
  position: number;
  items: CourseItem[];
  item_count?: number;
  duration_seconds?: number;
}

/** The payload the reorder endpoint accepts: the whole tree, in display order. */
export interface ReorderPayload {
  sections: Array<{ id: number; item_ids: number[] }>;
}
