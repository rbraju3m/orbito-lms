import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { Achievements } from '../api/types';
import { AchievementsRoute } from './AchievementsRoute';

const API = 'http://localhost:8000/api/v1';

function achievements(overrides: Partial<Achievements> = {}): Achievements {
  return {
    profile: {
      points_total: 340,
      current_streak_days: 3,
      longest_streak_days: 12,
      last_active_date: '2026-09-14',
      is_ranked: true,
    },
    badges: [
      {
        id: 'first.lesson',
        name: 'First step',
        description: 'Completed your first lesson.',
        tier: 'bronze',
        tier_label: 'Bronze',
        icon_url: null,
        criteria: { type: 'lessons_completed', threshold: 1 },
        is_held: true,
        awarded_at: '2026-09-01T10:00:00+00:00',
      },
      {
        id: 'points.1000',
        name: 'A thousand',
        description: 'Earned a thousand points.',
        tier: 'silver',
        tier_label: 'Silver',
        icon_url: null,
        criteria: { type: 'points_total', threshold: 1000 },
        is_held: false,
        awarded_at: null,
      },
    ],
    recent: [{ points: 10, reason: 'Finish a lesson', awarded_at: '2026-09-14T09:00:00+00:00' }],
    ...overrides,
  };
}

function serve(data: Achievements) {
  server.use(http.get(`${API}/achievements`, () => HttpResponse.json({ data })));
}

describe('AchievementsRoute', () => {
  it('shows what an unearned badge needs, rather than hiding it', async () => {
    /*
     * A wall of only what you already hold is a trophy cabinet. The next one
     * visible, with the number on it, is a reason to come back.
     */
    serve(achievements());
    renderWithRouter(<AchievementsRoute />);

    expect(await screen.findByText('A thousand')).toBeInTheDocument();
    expect(screen.getByText('Earn 1,000 points.')).toBeInTheDocument();
  });

  it('counts held badges against the whole wall', async () => {
    serve(achievements());
    renderWithRouter(<AchievementsRoute />);

    expect(await screen.findByText('1 of 2')).toBeInTheDocument();
  });

  it('shows the best streak when the current one is shorter', async () => {
    serve(achievements());
    renderWithRouter(<AchievementsRoute />);

    expect(await screen.findByText('3 days')).toBeInTheDocument();
    expect(screen.getByText('Best: 12 days')).toBeInTheDocument();
  });

  it('promises the opt-out costs nothing', async () => {
    /*
     * The fear this setting answers is "will turning it off take my points
     * away?". Saying so is the whole reason somebody uses it.
     */
    serve(achievements());
    renderWithRouter(<AchievementsRoute />);

    expect(
      await screen.findByText(/You keep every point, badge and day of your streak/),
    ).toBeInTheDocument();
  });

  it('sends the opt-out', async () => {
    let body: unknown;
    serve(achievements());
    server.use(
      http.patch(`${API}/achievements/ranking`, async ({ request }) => {
        body = await request.json();
        return HttpResponse.json({ data: { ...achievements().profile, is_ranked: false } });
      }),
    );

    renderWithRouter(<AchievementsRoute />);

    await userEvent.click(await screen.findByRole('switch', { name: 'Appear on leaderboards' }));

    expect(body).toEqual({ is_ranked: false });
  });

  it('renders a learner who has done nothing', async () => {
    serve(
      achievements({
        profile: {
          points_total: 0,
          current_streak_days: 0,
          longest_streak_days: 0,
          last_active_date: null,
          is_ranked: true,
        },
        recent: [],
      }),
    );
    renderWithRouter(<AchievementsRoute />);

    expect(await screen.findByText('0 days')).toBeInTheDocument();
    expect(screen.getByText(/Points appear here as you work/)).toBeInTheDocument();
  });
});
