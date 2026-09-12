import { screen, waitFor } from '@testing-library/react';
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

const PROVIDERS = [
  { value: 'manual' as const, label: 'Paste a link', available: true },
  { value: 'zoom' as const, label: 'Zoom', available: false },
];

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
          providers: canManage ? PROVIDERS : [],
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

  /*
   * Authoring. Until P16 nothing in the product could create a webinar: the
   * model, the registration flow and this screen all existed, and only a
   * factory ever made one.
   */

  it('offers nothing to author to somebody who may not', async () => {
    serve([webinar()]);
    renderWithRouter(<WebinarsRoute />);

    await screen.findByText('Open evening');
    expect(screen.queryByRole('button', { name: 'Schedule a webinar' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Manage/ })).not.toBeInTheDocument();
  });

  it('creates a webinar with the session it happens at', async () => {
    let body: Record<string, unknown> | null = null;
    serve([], true);
    server.use(
      http.post(`${API}/webinars`, async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: webinar({ status: 'draft' }) }, { status: 201 });
      }),
    );

    renderWithRouter(<WebinarsRoute />);

    await userEvent.click(await screen.findByRole('button', { name: 'Schedule a webinar' }));
    await userEvent.type(await screen.findByLabelText(/Title/), 'Open evening');
    await userEvent.type(screen.getByLabelText(/Join link/), 'https://meet.example.test/x');
    await userEvent.type(screen.getByLabelText(/Starts/), '2026-10-01T18:00');
    await userEvent.type(screen.getByLabelText(/Ends/), '2026-10-01T19:00');
    await userEvent.click(screen.getByRole('button', { name: 'Schedule it' }));

    await waitFor(() => expect(body).not.toBeNull());
    // The session travels with it: a webinar with no time cannot be published.
    expect(body).toMatchObject({
      title: 'Open evening',
      provider: 'manual',
      join_url: 'https://meet.example.test/x',
    });
  });

  it('renders only the moves the server says are open', async () => {
    // `available_actions` is the transition list the API enforces, so a
    // button that would 409 cannot exist.
    serve(
      [
        webinar({
          status: 'cancelled',
          status_label: 'Cancelled',
          available_actions: ['draft'],
          is_publishable: true,
          is_deletable: true,
        }),
      ],
      true,
    );

    renderWithRouter(<WebinarsRoute />);

    await userEvent.click(await screen.findByRole('button', { name: /Manage Open evening/ }));

    expect(await screen.findByRole('menuitem', { name: 'Take back to draft' })).toBeInTheDocument();
    expect(screen.queryByRole('menuitem', { name: 'Publish' })).not.toBeInTheDocument();
  });

  it('offers no delete once somebody has registered', async () => {
    serve(
      [webinar({ available_actions: ['draft', 'cancelled'], is_publishable: true, is_deletable: false })],
      true,
    );

    renderWithRouter(<WebinarsRoute />);

    await userEvent.click(await screen.findByRole('button', { name: /Manage Open evening/ }));

    // A place held is somebody's record — it is cancelled, never deleted.
    expect(await screen.findByRole('menuitem', { name: 'Call it off' })).toBeInTheDocument();
    expect(screen.queryByRole('menuitem', { name: 'Delete' })).not.toBeInTheDocument();
  });

  it('cannot publish a webinar with nothing to attend', async () => {
    serve(
      [
        webinar({
          status: 'draft',
          status_label: 'Draft',
          session: null,
          available_actions: ['published', 'cancelled'],
          is_publishable: false,
          is_deletable: true,
        }),
      ],
      true,
    );

    renderWithRouter(<WebinarsRoute />);

    await userEvent.click(await screen.findByRole('button', { name: /Manage Open evening/ }));

    // Present and disabled rather than hidden: the author needs to know the
    // button exists and what is missing.
    expect(await screen.findByRole('menuitem', { name: 'Publish' })).toHaveAttribute(
      'data-disabled',
      'true',
    );
  });
});
