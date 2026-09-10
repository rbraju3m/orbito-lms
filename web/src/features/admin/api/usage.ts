import { queryOptions } from '@tanstack/react-query';

import { apiGet } from '@/shared/api/client';

import { adminKeys } from './queries';

export type UsageMetric =
  | 'courses_total'
  | 'courses_published'
  | 'storage_bytes'
  | 'media_files'
  | 'students'
  | 'instructors'
  | 'downloads';

export interface LimitRow {
  metric: UsageMetric;
  label: string;
  /** Render `used` as a size rather than a count. */
  is_bytes: boolean;

  used: number;
  /** null is UNCAPPED, never zero. */
  limit: number | null;
  remaining: number | null;
  /** 0–1, or null when there is nothing to fill. */
  fraction: number | null;

  at_limit: boolean;
  over_limit: boolean;
  /**
   * Whether hitting this cap actually stops a write. Students and storage are
   * counted and shown but never enforced — a learner is not the one who can
   * fix their academy's plan.
   */
  enforced: boolean;
}

export interface AcademyUsage {
  plan: { slug: string; name: string } | null;
  any_at_limit: boolean;
  limits: LimitRow[];
}

export const academyUsageQuery = () =>
  queryOptions({
    queryKey: [...adminKeys.all, 'usage'] as const,
    queryFn: ({ signal }) => apiGet<AcademyUsage>('/admin/academy/usage', { signal }),
    /*
     * Counters move with every course, upload and enrolment in the academy,
     * so a stale meter is a wrong meter. Short, not live: this is a page
     * somebody opens to check, not a dashboard they watch.
     */
    staleTime: 10_000,
  });
