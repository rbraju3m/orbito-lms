import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl } from '@/shared/test/handlers';
import { renderWithProviders } from '@/shared/test/render';
import { server } from '@/shared/test/server';

import { StudentsPanel } from './StudentsPanel';

const COURSE = 'course-uuid';

function student(overrides: Record<string, unknown> = {}) {
  return {
    enrollment: {
      id: 'enr-1',
      status: 'active',
      status_label: 'In progress',
      source: 'free',
      is_active: true,
      enrolled_at: '2026-01-05T10:00:00Z',
      starts_at: null,
      expires_at: null,
      completed_at: null,
      suspended_at: null,
      suspended_reason: null,
      ...((overrides.enrollment as object) ?? {}),
    },
    student: { id: 'u1', name: 'Ada Lovelace', email: 'ada@example.com' },
    progress: {
      completed_items: 2,
      total_items: 4,
      percent: 50,
      is_complete: false,
      last_activity_at: '2026-02-01T10:00:00Z',
    },
    ...overrides,
  };
}

function roster(rows: unknown[], total = rows.length) {
  return HttpResponse.json({
    data: rows,
    meta: { current_page: 1, per_page: 20, total, last_page: Math.max(1, Math.ceil(total / 20)) },
    links: { first: null, prev: null, next: null, last: null },
  });
}

describe('StudentsPanel', () => {
  it('lists students with their progress', async () => {
    server.use(http.get(apiUrl(`/studio/courses/${COURSE}/students`), () => roster([student()])));

    renderWithProviders(<StudentsPanel courseId={COURSE} />);

    expect(await screen.findByText('Ada Lovelace')).toBeInTheDocument();
    expect(screen.getByText(/2\/4/)).toBeInTheDocument();
  });

  /*
   * Absent progress is not zero progress. A learner with nothing recorded has
   * not started; rendering 0% would claim we know they tried.
   */
  it('says "not started" rather than 0% when there is no progress', async () => {
    server.use(
      http.get(apiUrl(`/studio/courses/${COURSE}/students`), () =>
        roster([student({ progress: null })]),
      ),
    );

    renderWithProviders(<StudentsPanel courseId={COURSE} />);

    expect(await screen.findByText(/not started/i)).toBeInTheDocument();
    expect(screen.queryByText('0%')).not.toBeInTheDocument();
  });

  /*
   * Expiry is evaluated live on the server, so a row can still say `active`
   * while its date has passed. Showing the stored status alone would tell the
   * instructor the opposite of what the learner sees.
   */
  it('shows a lapsed enrolment as expired even when the stored status says active', async () => {
    server.use(
      http.get(apiUrl(`/studio/courses/${COURSE}/students`), () =>
        roster([
          student({
            enrollment: {
              status: 'active',
              status_label: 'In progress',
              is_active: false,
              expires_at: '2020-01-01T00:00:00Z',
            },
          }),
        ]),
      ),
    );

    renderWithProviders(<StudentsPanel courseId={COURSE} />);

    expect(await screen.findByText(/expired/i)).toBeInTheDocument();
  });

  it('offers an empty state that distinguishes "none yet" from "none match"', async () => {
    server.use(http.get(apiUrl(`/studio/courses/${COURSE}/students`), () => roster([])));

    const user = userEvent.setup();
    renderWithProviders(<StudentsPanel courseId={COURSE} />);

    expect(await screen.findByText(/no students yet/i)).toBeInTheDocument();

    await user.type(screen.getByLabelText(/search students/i), 'nobody');

    expect(await screen.findByText(/no students match/i)).toBeInTheDocument();
  });

  it('renders an error state with a retry rather than an empty table', async () => {
    server.use(
      http.get(apiUrl(`/studio/courses/${COURSE}/students`), () =>
        HttpResponse.json(
          { error: { code: 'forbidden', message: 'Nope.', details: [], request_id: 'X' } },
          { status: 403 },
        ),
      ),
    );

    renderWithProviders(<StudentsPanel courseId={COURSE} />);

    expect(await screen.findByRole('button', { name: /try again/i })).toBeInTheDocument();
  });

  it('suspends a learner through the confirm modal', async () => {
    let patched: unknown = null;

    server.use(
      http.get(apiUrl(`/studio/courses/${COURSE}/students`), () => roster([student()])),
      http.patch(apiUrl('/studio/enrollments/enr-1'), async ({ request }) => {
        patched = await request.json();
        return HttpResponse.json({ data: { ...student().enrollment, status: 'suspended' } });
      }),
    );

    const user = userEvent.setup();
    renderWithProviders(<StudentsPanel courseId={COURSE} />);

    await user.click(await screen.findByRole('button', { name: /actions for ada/i }));
    await user.click(await screen.findByRole('menuitem', { name: /suspend/i }));

    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: /^suspend$/i }));

    // Named transition, never a raw status — the legal moves live on the server.
    await waitFor(() => expect(patched).toEqual({ action: 'suspend' }));
  });
});
