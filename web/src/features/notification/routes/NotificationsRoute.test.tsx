import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it, vi } from 'vitest';

import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { AppNotification } from '../api/types';
import { NotificationsRoute } from './NotificationsRoute';

const API = 'http://localhost:8000/api/v1';

function notification(overrides: Partial<AppNotification> = {}): AppNotification {
  return {
    id: 'notif-uuid',
    type: 'announcement.published',
    title: 'Week 3 is up',
    body: 'New lessons.',
    action_label: 'Read the announcement',
    action_path: '/learn/course-uuid/announcements',
    meta: { course_title: 'Modern Bengali Poetry' },
    read_at: null,
    is_read: false,
    created_at: '2026-09-01T10:00:00+00:00',
    ...overrides,
  };
}

function serve(rows: AppNotification[], unread = rows.filter((r) => !r.is_read).length) {
  server.use(
    http.get(`${API}/notifications`, () =>
      HttpResponse.json({
        data: rows,
        meta: {
          current_page: 1,
          per_page: 20,
          total: rows.length,
          last_page: 1,
          unread_count: unread,
        },
        links: { first: null, prev: null, next: null, last: null },
      }),
    ),
  );
}

describe('NotificationsRoute', () => {
  it('leads with what is unread and offers to clear it', async () => {
    serve([
      notification(),
      notification({
        id: 'b',
        title: 'Your certificate is ready',
        is_read: true,
        read_at: '2026-09-02T00:00:00+00:00',
      }),
    ]);
    renderWithRouter(<NotificationsRoute />);

    expect(await screen.findByText('Week 3 is up')).toBeInTheDocument();
    expect(screen.getByText('New')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /mark all read/i })).toBeInTheDocument();
  });

  it('offers no clear-all when there is nothing to clear', async () => {
    serve([notification({ is_read: true, read_at: '2026-09-02T00:00:00+00:00' })], 0);
    renderWithRouter(<NotificationsRoute />);

    await screen.findByText('Week 3 is up');
    expect(screen.queryByRole('button', { name: /mark all read/i })).not.toBeInTheDocument();
  });

  it('marks one read', async () => {
    const read = vi.fn();
    serve([notification()]);
    server.use(
      http.post(`${API}/notifications/:id/read`, () => {
        read();
        return HttpResponse.json({ data: notification({ is_read: true }) });
      }),
    );

    renderWithRouter(<NotificationsRoute />);

    await userEvent.click(await screen.findByRole('button', { name: /^mark read$/i }));
    expect(read).toHaveBeenCalled();
  });

  it('routes internally on the stored relative path', async () => {
    /*
     * The server never stores an absolute URL — an academy can change
     * address, and a year-old email must not be the thing that discovers it.
     */
    serve([notification()]);
    const { queryClient: _queryClient } = renderWithRouter(<NotificationsRoute />, {
      path: '/notifications',
      route: '/notifications',
    });

    await userEvent.click(await screen.findByRole('button', { name: /read the announcement/i }));

    // The catch-all route in the test harness stands in for the destination.
    expect(await screen.findByTestId('elsewhere')).toBeInTheDocument();
  });

  it('tells somebody who is caught up that they are', async () => {
    serve([], 0);
    renderWithRouter(<NotificationsRoute />);

    expect(await screen.findByText('No notifications yet')).toBeInTheDocument();
  });
});
