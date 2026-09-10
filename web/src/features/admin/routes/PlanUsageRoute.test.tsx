import { screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, sessionFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { AcademyUsage, LimitRow } from '../api/usage';
import { PlanUsageRoute } from './PlanUsageRoute';

function row(overrides: Partial<LimitRow> = {}): LimitRow {
  return {
    metric: 'courses_total',
    label: 'Courses',
    is_bytes: false,
    used: 2,
    limit: 3,
    remaining: 1,
    fraction: 2 / 3,
    at_limit: false,
    over_limit: false,
    enforced: true,
    ...overrides,
  };
}

function serve(usage: Partial<AcademyUsage> = {}) {
  server.use(
    http.get(apiUrl('/auth/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['settings.view'] }) }),
    ),
    http.get(apiUrl('/admin/academy/usage'), () =>
      HttpResponse.json({
        data: {
          plan: { slug: 'starter', name: 'Starter' },
          any_at_limit: false,
          limits: [row()],
          ...usage,
        } satisfies AcademyUsage,
      }),
    ),
  );
}

describe('PlanUsageRoute', () => {
  it('shows what is used against what the plan allows', async () => {
    serve();
    renderWithRouter(<PlanUsageRoute />);

    expect(await screen.findByText('2 of 3')).toBeInTheDocument();
    expect(screen.getByText(/on Starter/)).toBeInTheDocument();
    // Asserted here so the "no bar when uncapped" test below is not vacuous.
    expect(screen.getByRole('progressbar')).toBeInTheDocument();
  });

  /* null is uncapped, and must never render as a cap of zero. */
  it('renders an uncapped metric as unlimited with no progress bar', async () => {
    serve({
      limits: [row({ limit: null, remaining: null, fraction: null, used: 9 })],
    });
    renderWithRouter(<PlanUsageRoute />);

    expect(await screen.findByText('9 — unlimited')).toBeInTheDocument();
    expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
  });

  it('warns once, at the top, when an enforced limit is full', async () => {
    serve({
      any_at_limit: true,
      limits: [row({ used: 3, remaining: 0, fraction: 1, at_limit: true })],
    });
    renderWithRouter(<PlanUsageRoute />);

    expect(await screen.findByRole('alert')).toHaveTextContent(/reached a limit/i);
    // Exactly one live region: the standing explanation is a `note`.
    expect(screen.getByText('At limit')).toBeInTheDocument();
  });

  /*
   * A downgrade puts an academy over its cap. Nothing is deleted, so the
   * screen has to be able to say a number larger than the allowance.
   */
  it('says over limit rather than clamping the figure', async () => {
    serve({
      any_at_limit: true,
      limits: [row({ used: 7, limit: 3, remaining: 0, fraction: 1, at_limit: true, over_limit: true })],
    });
    renderWithRouter(<PlanUsageRoute />);

    expect(await screen.findByText('7 of 3')).toBeInTheDocument();
    expect(screen.getByText('Over limit')).toBeInTheDocument();
  });

  /* The student cap is counted and shown, and must not look like a wall. */
  it('marks an unenforced metric as counted only', async () => {
    serve({
      limits: [
        row({
          metric: 'students',
          label: 'Students',
          used: 60,
          limit: 50,
          remaining: 0,
          fraction: 1,
          at_limit: true,
          over_limit: true,
          enforced: false,
        }),
      ],
    });
    renderWithRouter(<PlanUsageRoute />);

    expect(await screen.findByText('Counted only')).toBeInTheDocument();
    expect(screen.queryByText('Over limit')).not.toBeInTheDocument();
  });

  it('renders a byte metric as a size, not a count', async () => {
    serve({
      limits: [
        row({
          metric: 'storage_bytes',
          label: 'Storage used',
          is_bytes: true,
          used: 5 * 1024 * 1024 * 1024,
          limit: 100 * 1024 * 1024 * 1024,
          remaining: 95 * 1024 * 1024 * 1024,
          fraction: 0.05,
          enforced: false,
        }),
      ],
    });
    renderWithRouter(<PlanUsageRoute />);

    expect(await screen.findByText('5.0 GB of 100 GB')).toBeInTheDocument();
  });
});
