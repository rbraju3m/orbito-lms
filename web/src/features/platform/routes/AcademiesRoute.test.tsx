import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, sessionFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { Tenant } from '../api/types';
import { AcademiesRoute } from './AcademiesRoute';

function tenant(overrides: Partial<Tenant> = {}): Tenant {
  return {
    id: 'academy-1',
    slug: 'north-college',
    name: 'North College',
    status: 'active',
    status_label: 'Active',
    is_active: true,
    is_open: true,
    available_actions: ['suspend'],
    support_email: null,
    approved_at: '2026-09-01T10:00:00+00:00',
    created_at: '2026-08-30T10:00:00+00:00',
    suspended_reason: null,
    rejected_reason: null,
    subscription: {
      // Trialing, not active, so the academy's status and its subscription's
      // do not read identically — they are different facts and the screen has
      // to show both.
      status: 'trialing',
      status_label: 'Trialing',
      permits_writes: true,
      trial_ends_at: '2026-09-14T00:00:00+00:00',
      current_period_ends_at: '2026-10-01T00:00:00+00:00',
      cover_ends_at: '2026-10-01T00:00:00+00:00',
      grace_ends_at: '2026-10-08T00:00:00+00:00',
      canceled_at: null,
      plan: { slug: 'growth', name: 'Growth', price_minor: 500000, currency: 'BDT', limits: {} },
    },
    ...overrides,
  };
}

function serve(rows: Tenant[], sessionOverrides: Record<string, unknown> = {}) {
  server.use(
    http.get(apiUrl('/auth/me'), () =>
      HttpResponse.json({
        data: sessionFixture({ is_platform_operator: true, ...sessionOverrides }),
      }),
    ),
    http.get(apiUrl('/admin/tenants'), () =>
      HttpResponse.json({
        data: rows,
        meta: { current_page: 1, per_page: 20, total: rows.length, last_page: 1 },
        links: { first: null, prev: null, next: null, last: null },
      }),
    ),
  );
}

describe('AcademiesRoute', () => {
  it('lists academies with their status and plan', async () => {
    serve([tenant()]);
    renderWithRouter(<AcademiesRoute />);

    expect(await screen.findByRole('link', { name: 'North College' })).toBeInTheDocument();

    // Scoped to the table: "Active" is also a status FILTER option, and an
    // unscoped query would pass on the filter alone.
    const table = within(screen.getByRole('table'));

    expect(table.getByText('north-college')).toBeInTheDocument();
    // The academy's lifecycle status and its subscription's are two different
    // facts, and both belong on the row.
    expect(table.getByText('Active')).toBeInTheDocument();
    expect(table.getByText('Trialing')).toBeInTheDocument();
    expect(table.getByText('Growth')).toBeInTheDocument();
  });

  /*
   * The fact an operator most needs from this list. A lapsed academy reads and
   * exports fine, so "Active" alone would hide that its staff cannot save.
   */
  it('says when an academy cannot save changes', async () => {
    serve([
      tenant({
        subscription: {
          status: 'past_due',
          status_label: 'Past due',
          permits_writes: false,
          trial_ends_at: null,
          current_period_ends_at: '2026-08-01T00:00:00+00:00',
          cover_ends_at: '2026-08-01T00:00:00+00:00',
          grace_ends_at: '2026-08-08T00:00:00+00:00',
          canceled_at: null,
          plan: {
            slug: 'growth',
            name: 'Growth',
            price_minor: 500000,
            currency: 'BDT',
            limits: {},
          },
        },
      }),
    ]);
    renderWithRouter(<AcademiesRoute />);

    expect(await screen.findByText(/Past due — cannot save/)).toBeInTheDocument();
  });

  it('marks the academy the operator is currently inside', async () => {
    serve([tenant()], { academy: { id: 'academy-1', slug: 'north-college', name: 'North College' } });
    renderWithRouter(<AcademiesRoute />);

    const row = (await screen.findByRole('link', { name: 'North College' })).closest('tr');
    expect(within(row as HTMLElement).getByText('You are here')).toBeInTheDocument();
  });

  it('offers to provision the first academy when there are none', async () => {
    serve([]);
    renderWithRouter(<AcademiesRoute />);

    expect(await screen.findByText('No academies yet')).toBeInTheDocument();
    // The copy has to say the academy arrives SHUT, or an operator waits for
    // an owner who is being refused at the door.
    expect(screen.getByText(/created shut/)).toBeInTheDocument();
  });

  /*
   * A filtered empty list is not the same screen as an empty registry, and
   * offering "create the first one" to somebody who typed a search is wrong.
   */
  it('distinguishes a filtered empty result from an empty registry', async () => {
    serve([]);
    renderWithRouter(<AcademiesRoute />);

    await screen.findByText('No academies yet');

    await userEvent.type(screen.getByRole('textbox', { name: 'Search academies' }), 'nothing');

    expect(await screen.findByText('No academies match')).toBeInTheDocument();
  });

  it('renders the failure in place, with a retry', async () => {
    server.use(
      http.get(apiUrl('/auth/me'), () =>
        HttpResponse.json({ data: sessionFixture({ is_platform_operator: true }) }),
      ),
      http.get(apiUrl('/admin/tenants'), () =>
        HttpResponse.json(
          {
            error: {
              code: 'server_error',
              message: 'Something broke.',
              details: [],
              request_id: 'TEST',
            },
          },
          { status: 500 },
        ),
      ),
    );

    renderWithRouter(<AcademiesRoute />);

    expect(await screen.findByRole('button', { name: 'Try again' })).toBeInTheDocument();
  });
});
