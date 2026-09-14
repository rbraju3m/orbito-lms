import { screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { GuestConfirmRoute } from './GuestConfirmRoute';

const PATH = '/a/:academy/webinars/:slug/confirm';
const ROUTE = '/a/dhaka-art-school/webinars/open-evening/confirm?token=confirm-token';

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

describe('GuestConfirmRoute', () => {
  it('holds the place once and hands over the manage link', async () => {
    const bodies: unknown[] = [];
    server.use(
      http.post(
        apiUrl('/public/dhaka-art-school/guest-registrations/confirm'),
        async ({ request }) => {
          bodies.push(await request.json());
          return HttpResponse.json({ data: place() }, { status: 201 });
        },
      ),
    );

    renderWithRouter(<GuestConfirmRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByRole('heading', { name: 'You are registered' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Manage my place' })).toHaveAttribute(
      'href',
      '/a/dhaka-art-school/webinars/open-evening/place?token=place-token',
    );
    // The token travels in the body, and the confirm fires once.
    expect(bodies).toEqual([{ token: 'confirm-token' }]);
  });

  it('explains a room that filled while the link was waiting', async () => {
    server.use(
      http.post(apiUrl('/public/dhaka-art-school/guest-registrations/confirm'), () =>
        HttpResponse.json(
          {
            error: {
              code: 'webinar_full',
              message: 'This webinar has no places left.',
              details: [],
              request_id: 'r',
            },
          },
          { status: 409 },
        ),
      ),
    );

    renderWithRouter(<GuestConfirmRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByText(/last place went/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Back to the event' })).toHaveAttribute(
      'href',
      '/a/dhaka-art-school/webinars/open-evening',
    );
  });
});
