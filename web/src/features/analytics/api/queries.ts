import { queryOptions, useMutation } from '@tanstack/react-query';

import { API_BASE_URL, apiGet, apiPost, http } from '@/shared/api/client';

import type { CourseAnalytics, CourseFunnel, PlatformOverview, TrackedEvent } from './types';

export const analyticsKeys = {
  all: ['analytics'] as const,
  overview: (from: string, to: string) => [...analyticsKeys.all, 'overview', from, to] as const,
  course: (id: string, from: string, to: string) =>
    [...analyticsKeys.all, 'course', id, from, to] as const,
  funnel: (id: string) => [...analyticsKeys.all, 'funnel', id] as const,
};

/**
 * Rollups are rebuilt hourly at most, so these are stale-tolerant on purpose:
 * refetching a dashboard every thirty seconds asks the same question of the
 * same table and gets the same answer.
 */
const ROLLUP_STALE_TIME = 5 * 60_000;

export const overviewQuery = (from: string, to: string) =>
  queryOptions({
    queryKey: analyticsKeys.overview(from, to),
    queryFn: ({ signal }) =>
      apiGet<PlatformOverview>('/analytics/overview', { signal, params: { from, to } }),
    staleTime: ROLLUP_STALE_TIME,
  });

export const courseAnalyticsQuery = (courseId: string, from: string, to: string) =>
  queryOptions({
    queryKey: analyticsKeys.course(courseId, from, to),
    queryFn: ({ signal }) =>
      apiGet<CourseAnalytics>(`/analytics/courses/${courseId}`, { signal, params: { from, to } }),
    staleTime: ROLLUP_STALE_TIME,
  });

/** No range: a funnel is the present state of everybody enrolled, not a window. */
export const courseFunnelQuery = (courseId: string) =>
  queryOptions({
    queryKey: analyticsKeys.funnel(courseId),
    queryFn: ({ signal }) =>
      apiGet<CourseFunnel>(`/analytics/courses/${courseId}/funnel`, { signal }),
    staleTime: ROLLUP_STALE_TIME,
  });

/**
 * Fire-and-forget.
 *
 * Analytics OBSERVES the app; it must never be able to break it. Nothing here
 * surfaces an error, retries, or blocks a render — a lost view beacon is a
 * smaller problem than a page that fell over counting itself.
 */
export function trackEvents(events: TrackedEvent[]): void {
  if (events.length === 0) return;

  void apiPost('/analytics/track', { events }).catch(() => {
    // Deliberately silent. See above.
  });
}

/**
 * CSV download.
 *
 * Fetched as a blob through the shared client rather than opened in a new tab:
 * the API is a different origin, and a top-level navigation would depend on
 * the session cookie's SameSite policy rather than on the credentials the
 * client already sends. It also means a failure is an error state instead of a
 * blank tab.
 */
export function useCsvExport() {
  return useMutation({
    mutationFn: async ({ path, filename }: { path: string; filename: string }) => {
      const response = await http.get(path, {
        responseType: 'blob',
        baseURL: `${API_BASE_URL}/api/v1`,
      });

      const url = URL.createObjectURL(response.data as Blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      // Revoked immediately: the click has already started the download, and
      // an object URL left behind pins the whole blob in memory.
      URL.revokeObjectURL(url);
    },
  });
}
