import { fireEvent, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it, vi } from 'vitest';

import { apiUrl } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { Cohort, LiveProviderOption, LiveSession, Roster } from '../api/types';
import { LiveManager } from './LiveManager';

const COURSE = 'course-1';

const PROVIDERS: LiveProviderOption[] = [
  { value: 'manual', label: 'Paste a link', available: true },
  { value: 'zoom', label: 'Zoom', available: false },
  { value: 'google_meet', label: 'Google Meet', available: false },
];

function session(overrides: Partial<LiveSession> = {}): LiveSession {
  return {
    id: 's-1',
    title: 'Week 1 call',
    description: null,
    provider: 'manual',
    provider_label: 'Paste a link',
    status: 'scheduled',
    status_label: 'Scheduled',
    starts_at: '2026-10-01T13:00:00Z',
    ends_at: '2026-10-01T14:00:00Z',
    timezone: 'Asia/Dhaka',
    host: { name: 'Ines' },
    course: null,
    cohort: null,
    join_url: null,
    can_join: false,
    has_link: true,
    recording_url: null,
    created_at: '2026-09-12T00:00:00Z',
    ...overrides,
  };
}

function cohort(overrides: Partial<Cohort> = {}): Cohort {
  return {
    id: 'c-1',
    name: 'Autumn run',
    starts_at: '2026-10-01T00:00:00Z',
    ends_at: null,
    timezone: 'Asia/Dhaka',
    status: 'draft',
    status_label: 'Draft',
    capacity: null,
    places_remaining: null,
    enrollment_deadline: null,
    is_joinable: false,
    session_count: 0,
    enrollment_count: 0,
    is_deletable: true,
    ...overrides,
  };
}

function page<T>(rows: T[], meta: Record<string, unknown>) {
  return {
    data: rows,
    meta: { current_page: 1, per_page: 20, total: rows.length, last_page: 1, ...meta },
    links: { first: null, prev: null, next: null, last: null },
  };
}

function serve({
  sessions = [] as LiveSession[],
  cohorts = [] as Cohort[],
  canManage = true,
} = {}) {
  server.use(
    http.get(apiUrl(`/courses/${COURSE}/live-sessions`), () =>
      HttpResponse.json(
        page(sessions, { can_manage: canManage, providers: canManage ? PROVIDERS : [] }),
      ),
    ),
    http.get(apiUrl(`/courses/${COURSE}/cohorts`), () =>
      HttpResponse.json(page(cohorts, { can_manage: canManage })),
    ),
  );
}

describe('LiveManager', () => {
  it('explains, rather than offering buttons, to somebody who cannot schedule', async () => {
    serve({ canManage: false });

    renderWithRouter(<LiveManager courseId={COURSE} />);

    expect(await screen.findByText(/but not schedule them/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Schedule a session/ })).not.toBeInTheDocument();
  });

  /*
   * The times typed are the scheduler's wall clock; what goes over the wire is
   * the instant, in UTC, beside the zone it was meant in.
   */
  it('schedules a pasted-link session as an instant and the zone it was meant in', async () => {
    const posted = vi.fn();
    serve();
    server.use(
      http.post(apiUrl(`/courses/${COURSE}/live-sessions`), async ({ request }) => {
        posted(await request.json());
        return HttpResponse.json({ data: session() }, { status: 201 });
      }),
    );

    renderWithRouter(<LiveManager courseId={COURSE} />);

    await userEvent.click((await screen.findAllByRole('button', { name: 'Schedule a session' }))[0]!);
    await userEvent.type(screen.getByLabelText(/^Title/), 'Week 1 call');
    await userEvent.type(screen.getByLabelText(/^Join link/), 'https://meet.example.test/abc');
    fireEvent.change(screen.getByLabelText(/^Starts/), { target: { value: '2026-10-01T19:00' } });
    fireEvent.change(screen.getByLabelText(/^Ends/), { target: { value: '2026-10-01T20:00' } });
    await userEvent.click(screen.getByRole('button', { name: 'Schedule' }));

    await waitFor(() =>
      expect(posted).toHaveBeenCalledWith({
        title: 'Week 1 call',
        description: null,
        provider: 'manual',
        join_url: 'https://meet.example.test/abc',
        starts_at: new Date('2026-10-01T19:00').toISOString(),
        ends_at: new Date('2026-10-01T20:00').toISOString(),
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
      }),
    );
  });

  it('refuses to schedule a session that ends before it starts, before any round trip', async () => {
    const posted = vi.fn();
    serve();
    server.use(
      http.post(apiUrl(`/courses/${COURSE}/live-sessions`), () => {
        posted();
        return HttpResponse.json({ data: session() }, { status: 201 });
      }),
    );

    renderWithRouter(<LiveManager courseId={COURSE} />);

    await userEvent.click((await screen.findAllByRole('button', { name: 'Schedule a session' }))[0]!);
    await userEvent.type(screen.getByLabelText(/^Title/), 'Backwards');
    await userEvent.type(screen.getByLabelText(/^Join link/), 'https://meet.example.test/abc');
    fireEvent.change(screen.getByLabelText(/^Starts/), { target: { value: '2026-10-01T20:00' } });
    fireEvent.change(screen.getByLabelText(/^Ends/), { target: { value: '2026-10-01T19:00' } });
    await userEvent.click(screen.getByRole('button', { name: 'Schedule' }));

    expect(await screen.findByText('It has to end after it starts.')).toBeInTheDocument();
    expect(posted).not.toHaveBeenCalled();
  });

  /*
   * Deleting a run with sessions or learners would cascade them away. The
   * server says which runs may go; a delete button that would 409 never shows.
   */
  it('offers delete only for a run nobody is using', async () => {
    serve({
      cohorts: [
        cohort({ id: 'c-1', name: 'Autumn run', is_deletable: true }),
        cohort({ id: 'c-2', name: 'Spring run', is_deletable: false, enrollment_count: 12 }),
      ],
    });

    renderWithRouter(<LiveManager courseId={COURSE} />);

    expect(await screen.findByRole('button', { name: 'Delete Autumn run' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Delete Spring run' })).not.toBeInTheDocument();
  });

  it('marks an absent learner present from the roster', async () => {
    const posted = vi.fn();
    const roster: Roster = {
      session: { id: 's-1', title: 'Week 1 call' },
      expected: 2,
      present: 1,
      roster: [
        {
          user_id: 5,
          name: 'Ada',
          attended: true,
          joined_at: '2026-10-01T13:02:00Z',
          duration_seconds: 3000,
          source: 'self',
        },
        { user_id: 6, name: 'Grace', attended: false, joined_at: null, duration_seconds: null, source: null },
      ],
    };

    serve({ sessions: [session({ status: 'ended', status_label: 'Ended' })] });
    server.use(
      http.get(apiUrl('/live-sessions/s-1/attendance'), () => HttpResponse.json({ data: roster })),
      http.post(apiUrl('/live-sessions/s-1/attendance'), async ({ request }) => {
        posted(await request.json());
        return HttpResponse.json({ data: { marked: 1 } });
      }),
    );

    renderWithRouter(<LiveManager courseId={COURSE} />);

    await userEvent.click(await screen.findByRole('button', { name: 'Roster for Week 1 call' }));
    expect(await screen.findByText('1 of 2 attended.')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Mark present' }));

    await waitFor(() => expect(posted).toHaveBeenCalledWith({ user_ids: [6] }));
  });
});
