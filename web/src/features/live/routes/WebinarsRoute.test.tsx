import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { Webinar } from '../api/types';
import { WebinarsRoute } from './WebinarsRoute';

const API = 'http://localhost:8000/api/v1';

function webinar(overrides: Partial<Webinar> = {}): Webinar {
  return {
    id: 'webinar-uuid',
    slug: 'open-evening',
    title: 'Open evening',
    description: 'An hour on how the course works.',
    status: 'published',
    status_label: 'Published',
    capacity: null,
    places_remaining: null,
    is_paid: false,
    session: {
      id: 'session-uuid',
      starts_at: '2026-09-20T13:00:00+00:00',
      ends_at: '2026-09-20T14:00:00+00:00',
      timezone: 'Asia/Dhaka',
      status: 'scheduled',
    },
    is_registered: false,
    ...overrides,
  };
}

function serve(rows: Webinar[], canManage = false) {
  server.use(
    http.get(`${API}/webinars`, () =>
      HttpResponse.json({
        data: rows,
        meta: {
          current_page: 1,
          per_page: 20,
          total: rows.length,
          last_page: 1,
          can_manage: canManage,
        },
        links: { first: null, prev: null, next: null, last: null },
      }),
    ),
  );
}

describe('WebinarsRoute', () => {
  it('offers registration and shows the scheduled zone', async () => {
    serve([webinar()]);
    renderWithRouter(<WebinarsRoute />);

    expect(await screen.findByText('Open evening')).toBeInTheDocument();
    expect(screen.getByText(/Asia\/Dhaka/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Register' })).toBeInTheDocument();
  });

  it('says nothing about places when the webinar is uncapped', async () => {
    // Null places means uncapped, which is not the same as zero left.
    serve([webinar({ capacity: null, places_remaining: null })]);
    renderWithRouter(<WebinarsRoute />);

    await screen.findByText('Open evening');
    expect(screen.queryByText(/places left/)).not.toBeInTheDocument();
  });

  it('disables registration when it is full', async () => {
    serve([webinar({ capacity: 10, places_remaining: 0 })]);
    renderWithRouter(<WebinarsRoute />);

    expect(await screen.findByRole('button', { name: 'Full' })).toBeDisabled();
  });

  it('offers to cancel once registered', async () => {
    let body: string | null = null;
    serve([webinar({ is_registered: true })]);
    server.use(
      http.delete(`${API}/webinars/:id/register`, ({ request }) => {
        body = request.method;
        return HttpResponse.json({ data: webinar({ is_registered: false }) });
      }),
    );

    renderWithRouter(<WebinarsRoute />);

    await userEvent.click(await screen.findByRole('button', { name: 'Cancel' }));

    // Cancelling frees the place rather than deleting the record of it.
    expect(body).toBe('DELETE');
  });

  it('explains an empty list', async () => {
    serve([]);
    renderWithRouter(<WebinarsRoute />);

    expect(await screen.findByText('No webinars scheduled')).toBeInTheDocument();
    expect(screen.getByText(/whether or not you are on a course/i)).toBeInTheDocument();
  });
});
