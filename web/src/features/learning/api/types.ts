export type ItemProgressStatus = 'not_started' | 'in_progress' | 'completed';

export interface LearnerItem {
  id: string;
  type: 'lesson' | 'resource' | 'quiz' | 'assignment' | 'live_session';
  type_label: string;
  title: string;
  position: number;
  duration_seconds: number;
  is_preview: boolean;
  is_completable: boolean;
  status: ItemProgressStatus;
  watch_position_seconds: number;
}

export interface LearnerSection {
  id: number;
  title: string;
  position: number;
  items: LearnerItem[];
}

export interface CourseProgress {
  completed_items: number;
  total_items: number;
  percent: number;
  is_complete: boolean;
  started_at: string | null;
  completed_at: string | null;
  last_activity_at: string | null;
  last_item_id?: string | null;
}

/** Why access was granted or refused — the UI switches on `reason`. */
export interface AccessInfo {
  granted: boolean;
  reason: string;
  source: string;
  is_staff: boolean;
  expires_at: string | null;
}

export interface PlayerBootstrap {
  course: {
    id: string;
    slug: string;
    title: string;
    completion_mode: 'flexible' | 'strict';
    item_count: number;
    total_duration_seconds: number;
  };
  access: AccessInfo;
  progress: CourseProgress | null;
  curriculum: LearnerSection[];
}

export interface LessonPayload {
  body: string | null;
  format: 'html' | 'markdown';
  video_provider: string;
  video_url: string | null;
  video_signed_url: string | null;
  video_duration_seconds: number;
}

export interface ItemPayload {
  id: string;
  type: string;
  title: string;
  duration_seconds: number;
  is_preview: boolean;
  content: LessonPayload | Record<string, unknown>;
  previous_id: string | null;
  next_id: string | null;
}

export interface LessonNote {
  id: number;
  body: string;
  video_timestamp_seconds: number | null;
  created_at: string;
}

export interface ContinueLearningRow {
  course: {
    id: string;
    slug: string;
    title: string;
    subtitle: string | null;
    thumbnail_url: string | null;
  };
  progress: CourseProgress;
  resume_item_id: string | null;
}
