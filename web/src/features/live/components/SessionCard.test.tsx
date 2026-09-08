import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it, vi } from 'vitest';

import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { LiveSession } from '../api/types';
import { SessionCard } from './SessionCard';

const API = 'http://localhost:8000/api/v1';

function session(overrides: Partial<LiveSession> = {}): LiveSession {
  return {
    id: 'session-uuid',
    title: 'Week 1 call',
    description: null,
    provider: 'manual',
    provider_label: 'Paste a link',
    status: 'live',
    status_label: 'Live now',
    starts_at: '2026-09-15T13:00:00+00:00',
    ends_at: '2026-09-15T14:00:00+00:00',
    timezone: 'Asia/Dhaka',
    host: { name: 'Dr Bose' },
    course: { id: 'course-uuid', title: 'Modern Bengali Poetry' },
    cohort: null,
    join_url: 'https://meet.example.test/abc',
    can_join: true,
    has_link: true,
    recording_url: null,
    created_at: '2026-09-01T10:00:00+00:00',
    ...overrides,
  };
}

describe('SessionCard', () => {
  it('records attendance by joining, rather than rendering a bare link', async () => {
    /*
     * The click IS the attendance record — following the link is the only
     * signal every provider has in common, and the manual one reports
     * nothing. An anchor would leave every roster empty.
     */
    const joined = vi.fn();
    const open = vi.spyOn(window, 'open').mockImplementation(() => null);

    server.use(
      http.post(`${API}/live-sessions/:id/join`, () => {
        joined();
        return HttpResponse.json({
          data: { join_url: 'https://meet.example.test/abc', session: session() },
        });
      }),
    );

    renderWithRouter(<SessionCard session={session()} />);

    // There is no link to follow without asking the server first.
    expect(screen.queryByRole('link', { name: /join/i })).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Join' }));

    expect(joined).toHaveBeenCalled();
    expect(open).toHaveBeenCalledWith('https://meet.example.test/abc', '_blank', 'noopener');

    open.mockRestore();
  });

  it('says when the window opens rather than offering a dead button', async () => {
    renderWithRouter(
      <SessionCard session={session({ status: 'scheduled', can_join: false, join_url: null })} />,
    );

    expect(await screen.findByText('Opens 15 min before')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Join' })).not.toBeInTheDocument();
  });

  it('says a placeholder has no link yet', async () => {
    // A session the author has not finished — the same treatment a lesson
    // with no body gets.
    renderWithRouter(
      <SessionCard
        session={session({ status: 'scheduled', can_join: false, has_link: false, join_url: null })}
      />,
    );

    expect(await screen.findByText('No link yet')).toBeInTheDocument();
  });

  it('shows the zone it was scheduled in, beside the local time', async () => {
    // "7pm Dhaka" is what the instructor said; the local rendering is what
    // the learner needs. Both, or somebody gets it wrong.
    renderWithRouter(<SessionCard session={session()} />);

    expect(await screen.findByText(/Asia\/Dhaka/)).toBeInTheDocument();
  });

  it('offers the recording once there is one', async () => {
    renderWithRouter(
      <SessionCard
        session={session({
          status: 'ended',
          can_join: false,
          recording_url: 'https://cdn.example.test/rec.mp4',
        })}
      />,
    );

    expect(await screen.findByRole('link', { name: /recording/i })).toBeInTheDocument();
    expect(screen.getByText('Ended')).toBeInTheDocument();
  });
});
