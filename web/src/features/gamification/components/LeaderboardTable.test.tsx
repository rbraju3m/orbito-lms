import { screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { Leaderboard } from '../api/types';
import { LeaderboardTable } from './LeaderboardTable';

const API = 'http://localhost:8000/api/v1';

function board(overrides: Partial<Leaderboard> = {}): Leaderboard {
  return {
    period: 'weekly',
    period_label: 'This week',
    period_start: '2026-09-08',
    computed_at: '2026-09-14T10:00:00+00:00',
    entries: [
      { rank: 1, name: 'Bina Roy', points: 320, is_you: false },
      { rank: 2, name: 'Rumi Haque', points: 210, is_you: true },
    ],
    me: { rank: 2, points: 210 },
    ...overrides,
  };
}

function serve(data: Leaderboard) {
  server.use(http.get(`${API}/leaderboard`, () => HttpResponse.json({ data })));
}

describe('LeaderboardTable', () => {
  it('defaults to the week, not all time', async () => {
    /*
     * An all-time board nobody new can appear on stops being a competition
     * and becomes a list of who joined early — discouraging to exactly the
     * people it is meant to pull in.
     */
    serve(board());
    renderWithRouter(<LeaderboardTable />);

    expect(await screen.findByText('Bina Roy')).toBeInTheDocument();
    expect(screen.getByRole('radio', { name: 'This week' })).toBeChecked();
  });

  it('marks the caller without needing to know their id', async () => {
    serve(board());
    renderWithRouter(<LeaderboardTable />);

    expect(await screen.findByText('You')).toBeInTheDocument();
  });

  it('tells somebody off the bottom where they stand', async () => {
    /*
     * The only thing on this screen useful to somebody outside the top fifty,
     * and impossible to work out client-side when they are not in the payload.
     */
    serve(
      board({
        entries: [{ rank: 1, name: 'Bina Roy', points: 320, is_you: false }],
        me: { rank: 137, points: 12 },
      }),
    );
    renderWithRouter(<LeaderboardTable />);

    expect(await screen.findByText(/You are 137th with 12 points/)).toBeInTheDocument();
  });

  it('says how stale the snapshot is', async () => {
    serve(board());
    renderWithRouter(<LeaderboardTable />);

    expect(await screen.findByText(/^Updated /)).toBeInTheDocument();
  });

  it('explains an empty board rather than showing an empty table', async () => {
    serve(board({ entries: [], me: null }));
    renderWithRouter(<LeaderboardTable />);

    expect(await screen.findByText('No board yet')).toBeInTheDocument();
    expect(screen.getByText(/rebuilt hourly/i)).toBeInTheDocument();
  });
});
