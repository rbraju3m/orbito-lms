/**
 * Analytics wire contract. Source of truth: docs/API.md.
 *
 * Every figure here comes from a ROLLUP, never from the event log (ADR-08).
 * That is why there is no "events" type: the log is a write path, and a screen
 * that could query it would be a second definition of a metric one release
 * later.
 */

export interface AnalyticsRange {
  from: string;
  to: string;
  /** Always 'UTC'. Said by the API rather than assumed by the reader. */
  timezone: string;
}

export interface PlatformTotals {
  new_users: number;
  new_enrollments: number;
  completions: number;
  revenue_minor: number;
  /**
   * NOT a sum. Distinct people cannot be added across days without counting a
   * regular five times over, so the API reports the busiest single day and
   * names the field for what it is.
   */
  peak_daily_active: number;
}

export interface PlatformPoint {
  date: string;
  new_users: number;
  new_enrollments: number;
  completions: number;
  revenue_minor: number;
  active_learners: number;
}

export interface CourseLeaderboardRow {
  course: { id: string; title: string; slug: string };
  views: number;
  enrollments: number;
  completions: number;
  revenue_minor: number;
}

export interface PlatformOverview {
  range: AnalyticsRange;
  totals: PlatformTotals;
  /** The same window immediately before, for a "vs. previous period" delta. */
  previous: PlatformTotals;
  series: PlatformPoint[];
  currency: string;
  top_courses: CourseLeaderboardRow[];
}

export interface CourseTotals {
  views: number;
  enrollments: number;
  completions: number;
  revenue_minor: number;
  peak_daily_active: number;
}

export interface CoursePoint {
  date: string;
  views: number;
  enrollments: number;
  completions: number;
  revenue_minor: number;
  active_learners: number;
}

export interface CourseAnalytics {
  range: AnalyticsRange;
  course: { id: string; title: string; slug: string };
  totals: CourseTotals;
  series: CoursePoint[];
  currency: string;
}

export interface FunnelItem {
  item_id: string;
  title: string;
  type: string;
  position: number;
  section_title: string | null;
  started: number;
  completed: number;
  drop_off_rate: number;
  /** Null when nobody watched — not the same as zero seconds. */
  avg_seconds: number | null;
  /** A funnel is a snapshot, so the reader needs to know how stale it is. */
  computed_at: string;
}

export interface CourseFunnel {
  course: { id: string; title: string };
  items: FunnelItem[];
}

/** What a client may raise. The server refuses everything else — see EventName. */
export type TrackableEvent =
  'course_viewed' | 'item_started' | 'search_performed' | 'cart_abandoned';

export interface TrackedEvent {
  name: TrackableEvent;
  occurred_at?: string;
  session_id?: string;
  course_id?: string;
  course_item_id?: string;
  properties?: Record<string, unknown>;
  source?: 'web' | 'mobile';
}
