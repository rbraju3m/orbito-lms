import { screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { PlatformOverview } from '../api/types';
import { AnalyticsOverviewRoute } from './AnalyticsOverviewRoute';

const API = 'http://localhost:8000/api/v1';

function overview(overrides: Partial<PlatformOverview> = {}): PlatformOverview {
  return {
    range: { from: '2026-08-11', to: '2026-09-09', timezone: 'UTC' },
    totals: {
      new_users: 12,
      new_enrollments: 40,
      completions: 9,
      revenue_minor: 500000,
      peak_daily_active: 11,
    },
    previous: {
      new_users: 10,
      new_enrollments: 20,
      completions: 9,
      revenue_minor: 0,
      peak_daily_active: 8,
    },
    series: [
      {
        date: '2026-09-08',
        new_users: 1,
        new_enrollments: 2,
        completions: 0,
        revenue_minor: 0,
        active_learners: 3,
      },
      {
        date: '2026-09-09',
        new_users: 2,
        new_enrollments: 5,
        completions: 1,
        revenue_minor: 500000,
        active_learners: 11,
      },
    ],
    currency: 'BDT',
    top_courses: [
      {
        course: {
          id: 'course-uuid',
          title: 'Modern Bengali Poetry',
          slug: 'modern-bengali-poetry',
        },
        views: 40,
        enrollments: 7,
        completions: 2,
        revenue_minor: 500000,
      },
    ],
    ...overrides,
  };
}

function serve(data: PlatformOverview) {
  server.use(http.get(`${API}/analytics/overview`, () => HttpResponse.json({ data })));
}

describe('AnalyticsOverviewRoute', () => {
  it('says which timezone the days are in', async () => {
    /*
     * "Yesterday" means different things in Dhaka and Denver. The rollups are
     * keyed on UTC days, so the page says so rather than leaving a reader to
     * assume their own.
     */
    serve(overview());
    renderWithRouter(<AnalyticsOverviewRoute />);

    expect(await screen.findByText(/2026-08-11 to 2026-09-09 · UTC/)).toBeInTheDocument();
  });

  it('compares against the same window before it', async () => {
    serve(overview());
    renderWithRouter(<AnalyticsOverviewRoute />);

    // 40 vs 20 — the arithmetic is the server's, not the component's.
    expect(await screen.findByText('+100.0%')).toBeInTheDocument();
    expect(screen.getAllByText('vs. previous period').length).toBeGreaterThan(0);
  });

  it('says "new" rather than an infinite percentage', async () => {
    /*
     * Revenue went 0 → 500000. Dividing by zero gives Infinity, and "+∞%" on
     * a dashboard is how somebody learns not to trust it.
     */
    serve(overview());
    renderWithRouter(<AnalyticsOverviewRoute />);

    expect(await screen.findByText('new')).toBeInTheDocument();
  });

  it('names the active-learner figure for what it is', async () => {
    // Distinct people cannot be summed across days without counting a regular
    // five times over, so the API reports a peak and the tile says so.
    serve(overview());
    renderWithRouter(<AnalyticsOverviewRoute />);

    expect(await screen.findByText('Busiest day')).toBeInTheDocument();
    expect(screen.getByText('Most active learners in one day')).toBeInTheDocument();
  });

  it('explains an empty leaderboard by how the rollups are built', async () => {
    serve(overview({ top_courses: [], series: [] }));
    renderWithRouter(<AnalyticsOverviewRoute />);

    expect(await screen.findByText('Nothing to report yet')).toBeInTheDocument();
    expect(screen.getByText(/rollups are built nightly/i)).toBeInTheDocument();
  });

  it('describes the chart for a reader who cannot see it', async () => {
    serve(overview());
    renderWithRouter(<AnalyticsOverviewRoute />);

    // An SVG is not a chart to a screen reader; the numbers also live in the
    // table beneath it.
    expect(
      await screen.findByRole('img', { name: /Enrolments and Completions/i }),
    ).toBeInTheDocument();
  });
});
