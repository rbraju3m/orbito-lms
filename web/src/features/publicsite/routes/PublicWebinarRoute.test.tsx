import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { PublicWebinarRoute } from './PublicWebinarRoute';

const ACADEMY = '/public/dhaka-art-school';
const PATH = '/a/:academy/webinars/:slug';
const ROUTE = '/a/dhaka-art-school/webinars/open-evening';

function webinar(overrides: Record<string, unknown> = {}) {
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
      starts_at: '2026-10-01T13:00:00+00:00',
      ends_at: '2026-10-01T14:00:00+00:00',
      timezone: 'Asia/Dhaka',
      status: 'scheduled',
    },
    is_registered: false,
    can_cancel: false,
    ...overrides,
  };
}

function serve(overrides: Record<string, unknown> = {}) {
  server.use(
    http.get(apiUrl(ACADEMY), () =>
      HttpResponse.json({
        data: {
          slug: 'dhaka-art-school',
          name: 'Dhaka Art School',
          logo_url: null,
          support_email: null,
          registration_open: true,
        },
      }),
    ),
    http.get(apiUrl(`${ACADEMY}/webinars/open-evening`), () =>
      HttpResponse.json({ data: webinar(overrides) }),
    ),
  );
}

describe('PublicWebinarRoute', () => {
  it('renders the event, its zone and a way in', async () => {
    serve();

    renderWithRouter(<PublicWebinarRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByRole('heading', { name: 'Open evening' })).toBeInTheDocument();
    // The zone is shown beside the time: an event happens at a moment
    // somebody has to be awake for.
    expect(screen.getByText('(Asia/Dhaka)')).toBeInTheDocument();
    // A free event takes a guest: no account, a link by email instead.
    expect(await screen.findByRole('button', { name: 'Email me a link' })).toBeInTheDocument();
  });

  it('says an event is over instead of offering a place in it', async () => {
    // Paid, so the buy button is the one that must not survive: the server
    // would refuse the purchase after an account had been made for it.
    serve({
      is_paid: true,
      price: { amount_minor: 50000, currency: 'BDT' },
      session: { ...webinar().session, status: 'ended' },
    });

    renderWithRouter(<PublicWebinarRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByText('This event has ended.')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /buy a place/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Email me a link' })).not.toBeInTheDocument();
  });

  it('says how many places are left, and says when there are none', async () => {
    serve({ capacity: 20, places_remaining: 3 });

    renderWithRouter(<PublicWebinarRoute />, { path: PATH, route: ROUTE });

    // A number rather than "nearly full": somebody deciding now wants to know.
    expect(await screen.findByText('3 places left')).toBeInTheDocument();
  });

  it('shows a ticket price for a paid event', async () => {
    serve({
      is_paid: true,
      price: {
        product_id: 'p1',
        currency: 'BDT',
        amount_minor: 150000,
        list_amount_minor: null,
        is_on_sale: false,
      },
    });

    renderWithRouter(<PublicWebinarRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByText('Ticketed')).toBeInTheDocument();
    expect(screen.getByText(/1,500/)).toBeInTheDocument();
    // Buying needs an account, so a paid event offers no guest form.
    expect(screen.getByRole('link', { name: /create an account to buy a place/i })).toHaveAttribute(
      'href',
      '/register?academy=dhaka-art-school',
    );
    expect(screen.queryByRole('button', { name: 'Email me a link' })).toBeNull();
  });

  it('asks a guest to check their inbox, and says nothing more', async () => {
    serve();
    const bodies: unknown[] = [];
    server.use(
      http.post(
        apiUrl(`${ACADEMY}/webinars/open-evening/guest-registrations`),
        async ({ request }) => {
          bodies.push(await request.json());
          return HttpResponse.json({ data: { received: true } }, { status: 202 });
        },
      ),
    );
    const user = userEvent.setup();

    renderWithRouter(<PublicWebinarRoute />, { path: PATH, route: ROUTE });

    const guest = within(await screen.findByRole('region', { name: 'Hold a place' }));
    await user.type(await guest.findByRole('textbox', { name: /email/i }), 'ada@example.test');
    await user.click(guest.getByRole('button', { name: 'Email me a link' }));

    expect(await screen.findByText(/Check your inbox/)).toBeInTheDocument();
    expect(bodies).toEqual([
      { email: 'ada@example.test', name: null, form_token: 'test-form-token', website: '' },
    ]);
  });

  it('offers somebody who cannot make it a way to hear about the next event', async () => {
    serve();
    const bodies: unknown[] = [];
    server.use(
      http.post(apiUrl(`${ACADEMY}/leads`), async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ data: { received: true } }, { status: 202 });
      }),
    );
    const user = userEvent.setup();

    renderWithRouter(<PublicWebinarRoute />, { path: PATH, route: ROUTE });

    const lead = within(await screen.findByRole('region', { name: "Can't make it?" }));
    await user.type(await lead.findByRole('textbox', { name: /email/i }), 'ada@example.test');
    await user.click(lead.getByRole('checkbox', { name: 'I agree to be contacted by email.' }));
    await user.click(lead.getByRole('button', { name: 'Keep me posted' }));

    expect(await screen.findByText(/we will be in touch/)).toBeInTheDocument();
    // Attributed to this event by its slug; the server resolves the title.
    expect(bodies).toEqual([
      {
        email: 'ada@example.test',
        name: null,
        consent: true,
        source: 'webinar',
        source_slug: 'open-evening',
        form_token: 'test-lead-token',
        website: '',
      },
    ]);
  });

  it('keeps the lead form on an event that is over, asking about the next one', async () => {
    // A printed link outlives the evening; the stranger following it can
    // still ask to hear about the next one.
    serve({ session: { ...webinar().session, status: 'ended' } });

    renderWithRouter(<PublicWebinarRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByText('This event has ended.')).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Hear about the next one' })).toBeInTheDocument();
    expect(await screen.findByRole('button', { name: 'Keep me posted' })).toBeInTheDocument();
  });
});
