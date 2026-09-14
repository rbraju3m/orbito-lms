import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { GuestPlaceRoute } from './GuestPlaceRoute';

const BASE = '/public/dhaka-art-school/guest-places';
const PATH = '/a/:academy/webinars/:slug/place';
const ROUTE = '/a/dhaka-art-school/webinars/open-evening/place?token=place-token';

function place(overrides: Record<string, unknown> = {}) {
  return {
    status: 'registered',
    email: 'ada@example.test',
    name: 'Ada',
    can_cancel: true,
    can_join: false,
    token: 'place-token',
    webinar: {
      id: 'w',
      slug: 'open-evening',
      title: 'Open evening',
      description: null,
      status: 'published',
      status_label: 'Published',
      capacity: null,
      places_remaining: null,
      is_paid: false,
      session: {
        id: 's',
        starts_at: '2026-10-01T13:00:00+00:00',
        ends_at: '2026-10-01T14:00:00+00:00',
        timezone: 'Asia/Dhaka',
        status: 'scheduled',
      },
      is_registered: true,
      can_cancel: true,
    },
    ...overrides,
  };
}

describe('GuestPlaceRoute', () => {
  it('gives the place up only after asking', async () => {
    let cancelled = false;
    server.use(
      http.post(apiUrl(`${BASE}/show`), () => HttpResponse.json({ data: place() })),
      http.post(apiUrl(`${BASE}/cancel`), () => {
        cancelled = true;
        return HttpResponse.json({
          data: place({ status: 'cancelled', can_cancel: false }),
        });
      }),
    );
    const user = userEvent.setup();

    renderWithRouter(<GuestPlaceRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByText('You hold a place')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Give up my place' }));
    expect(cancelled).toBe(false);

    await user.click(screen.getByRole('button', { name: 'Yes, give it up' }));

    expect(await screen.findByText(/You gave this place up/)).toBeInTheDocument();
    expect(cancelled).toBe(true);
    expect(screen.queryByRole('button', { name: 'Give up my place' })).toBeNull();
  });

  it('fetches the meeting link when the room is open', async () => {
    server.use(
      http.post(apiUrl(`${BASE}/show`), () =>
        HttpResponse.json({ data: place({ can_join: true }) }),
      ),
      http.post(apiUrl(`${BASE}/join`), () =>
        HttpResponse.json({ data: { join_url: 'https://meet.example.test/room' } }),
      ),
    );
    const user = userEvent.setup();

    renderWithRouter(<GuestPlaceRoute />, { path: PATH, route: ROUTE });

    await user.click(await screen.findByRole('button', { name: 'Join now' }));

    expect(await screen.findByRole('link', { name: 'Open the meeting' })).toHaveAttribute(
      'href',
      'https://meet.example.test/room',
    );
  });

  it('says a link has expired rather than showing an error screen', async () => {
    server.use(
      http.post(apiUrl(`${BASE}/show`), () =>
        HttpResponse.json(
          {
            error: {
              code: 'guest_link_invalid',
              message: 'This link has expired or is not valid.',
              details: [],
              request_id: 'r',
            },
          },
          { status: 422 },
        ),
      ),
    );

    renderWithRouter(<GuestPlaceRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByText(/expired or is not valid/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Back to the event' })).toBeInTheDocument();
  });
});
