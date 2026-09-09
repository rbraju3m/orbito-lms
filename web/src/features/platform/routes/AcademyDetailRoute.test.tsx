import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it, vi } from 'vitest';

import { apiUrl, sessionFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { Tenant } from '../api/types';
import { AcademyDetailRoute } from './AcademyDetailRoute';

function tenant(overrides: Partial<Tenant> = {}): Tenant {
  return {
    id: 'academy-1',
    slug: 'north-college',
    name: 'North College',
    status: 'pending',
    status_label: 'Awaiting approval',
    is_active: true,
    is_open: false,
    available_actions: ['approve', 'reject', 'suspend'],
    support_email: null,
    approved_at: null,
    registration_mode: 'open',
    registration_mode_label: 'Anyone with the link',
    created_at: '2026-08-30T10:00:00+00:00',
    suspended_reason: null,
    rejected_reason: null,
    subscription: null,
    ...overrides,
  };
}

function serve(row: Tenant, sessionOverrides: Record<string, unknown> = {}) {
  server.use(
    http.get(apiUrl('/auth/me'), () =>
      HttpResponse.json({
        data: sessionFixture({ is_platform_operator: true, ...sessionOverrides }),
      }),
    ),
    http.get(apiUrl('/admin/tenants/north-college'), () => HttpResponse.json({ data: row })),
    http.get(apiUrl('/admin/plans'), () => HttpResponse.json({ data: [] })),
  );
}

function renderDetail() {
  return renderWithRouter(<AcademyDetailRoute />, {
    path: '/platform/academies/:slug',
    route: '/platform/academies/north-college',
  });
}

describe('AcademyDetailRoute', () => {
  /*
   * The whole point of `available_actions`. The server computes it from the
   * same rule ChangeTenantStatus enforces, so a button that would 409 must
   * never render — which means this screen may not decide for itself.
   */
  it('renders a button per action the server says is legal, and no others', async () => {
    serve(tenant());
    renderDetail();

    expect(await screen.findByRole('button', { name: 'Approve' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Reject' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Suspend' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Reinstate' })).not.toBeInTheDocument();
  });

  it('offers nothing on a rejected academy', async () => {
    serve(
      tenant({
        status: 'rejected',
        status_label: 'Rejected',
        available_actions: [],
        rejected_reason: 'Duplicate signup.',
      }),
    );
    renderDetail();

    expect(await screen.findByText(/Duplicate signup/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Suspend' })).not.toBeInTheDocument();
  });

  /*
   * Suspend stays legal on an already-suspended academy — it is how the reason
   * is amended — so the label has to say that rather than implying it would
   * suspend something twice.
   */
  it('relabels suspend as an amendment once the academy is already suspended', async () => {
    serve(
      tenant({
        status: 'suspended',
        status_label: 'Suspended',
        available_actions: ['suspend', 'reactivate'],
        suspended_reason: 'Chargeback',
      }),
    );
    renderDetail();

    expect(await screen.findByRole('button', { name: 'Update reason' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Reinstate' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Suspend' })).not.toBeInTheDocument();
  });

  it('asks for a reason before suspending, and sends it', async () => {
    const patched = vi.fn();

    serve(tenant({ status: 'active', status_label: 'Active', available_actions: ['suspend'] }));
    server.use(
      http.patch(apiUrl('/admin/tenants/north-college'), async ({ request }) => {
        patched(await request.json());
        return HttpResponse.json({ data: tenant({ status: 'suspended' }) });
      }),
    );

    renderDetail();

    await userEvent.click(await screen.findByRole('button', { name: 'Suspend' }));

    const dialog = within(await screen.findByRole('dialog'));

    await userEvent.type(dialog.getByLabelText('Reason'), 'Chargeback');
    // Scoped: the page's own Suspend button is still in the tree behind the
    // overlay, and an unscoped query would re-open the modal instead.
    await userEvent.click(dialog.getByRole('button', { name: 'Suspend' }));

    expect(patched).toHaveBeenCalledWith({ action: 'suspend', reason: 'Chargeback' });
  });

  it('explains that suspension keeps the data', async () => {
    serve(tenant({ status: 'active', status_label: 'Active', available_actions: ['suspend'] }));
    renderDetail();

    await userEvent.click(await screen.findByRole('button', { name: 'Suspend' }));

    expect(await screen.findByText(/reinstating is a status change and not a restore/)).toBeInTheDocument();
  });

  it('offers to enter an academy the operator is not inside', async () => {
    serve(tenant({ status: 'active', status_label: 'Active', is_open: true }));
    renderDetail();

    expect(await screen.findByRole('button', { name: 'Enter academy' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Step out' })).not.toBeInTheDocument();
  });

  it('offers to step out of the academy the operator is inside', async () => {
    serve(tenant({ status: 'active', status_label: 'Active', is_open: true }), {
      academy: { id: 'academy-1', slug: 'north-college', name: 'North College' },
    });
    renderDetail();

    expect(await screen.findByRole('button', { name: 'Step out' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Enter academy' })).not.toBeInTheDocument();
  });

  /*
   * A lapse is 402, never 403 — the academy reads and exports fine. An
   * operator looking at a support ticket needs to know which of the two it is.
   */
  it('says a lapsed academy cannot save, and that reading still works', async () => {
    serve(
      tenant({
        status: 'active',
        status_label: 'Active',
        is_open: true,
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
            limits: { courses: 50 },
          },
        },
      }),
    );
    renderDetail();

    expect(await screen.findByText(/cannot save changes/)).toBeInTheDocument();
    expect(screen.getByText(/Reading and exporting still work/)).toBeInTheDocument();
  });

  /*
   * Plan limits are stored and counted and enforced NOWHERE (Phase 16).
   * Rendering them without saying so would read as a cap that bites.
   */
  it('does not imply plan limits are enforced', async () => {
    serve(
      tenant({
        status: 'active',
        status_label: 'Active',
        is_open: true,
        subscription: {
          status: 'active',
          status_label: 'Active',
          permits_writes: true,
          trial_ends_at: null,
          current_period_ends_at: '2026-10-01T00:00:00+00:00',
          cover_ends_at: '2026-10-01T00:00:00+00:00',
          grace_ends_at: null,
          canceled_at: null,
          plan: {
            slug: 'growth',
            name: 'Growth',
            price_minor: 500000,
            currency: 'BDT',
            limits: { courses: 50 },
          },
        },
      }),
    );
    renderDetail();

    expect(await screen.findByText('courses')).toBeInTheDocument();
    expect(screen.getByText(/not yet enforced/)).toBeInTheDocument();
  });

  /*
   * Assigning a plan is also how a lapsed academy is REVIVED — one endpoint,
   * because paying and reopening are the same event. The modal has to say so;
   * "assign plan" does not sound like "unblock their staff".
   */
  it('offers the plan picker seeded from the academy’s current plan', async () => {
    serve(
      tenant({
        status: 'active',
        status_label: 'Active',
        is_open: true,
        subscription: {
          status: 'past_due',
          status_label: 'Past due',
          permits_writes: false,
          trial_ends_at: null,
          current_period_ends_at: '2026-08-01T00:00:00+00:00',
          cover_ends_at: '2026-08-01T00:00:00+00:00',
          grace_ends_at: null,
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
    );
    server.use(
      http.get(apiUrl('/admin/plans'), () =>
        HttpResponse.json({
          data: [
            {
              slug: 'growth',
              name: 'Growth',
              price_minor: 500000,
              currency: 'BDT',
              billing_period: 'monthly',
              trial_days: 14,
              grace_days: 7,
              limits: {},
              features: {},
              is_active: true,
            },
          ],
        }),
      ),
    );

    renderDetail();

    await userEvent.click(await screen.findByRole('button', { name: 'Plan' }));

    const dialog = within(await screen.findByRole('dialog'));

    // The picker opens on the plan the academy is ALREADY on, so "save" with
    // only a date is a renewal rather than a silent downgrade.
    // By placeholder: the modal's own accessible name is "Plan and renewal",
    // so a label query for /^Plan/ matches the dialog as well as the field.
    const picker = dialog.getByPlaceholderText('Choose a plan') as HTMLInputElement;

    // Contains, not equals: `formatMinor` renders in the READER's locale, and
    // asserting an exact currency string ties this test to one ICU build.
    expect(picker.value).toContain('Growth');
    expect(picker.value).toContain('monthly');

    expect(dialog.getByText(/reopens a lapsed academy/)).toBeInTheDocument();
  });

  it('renders the failure in place, with a retry', async () => {
    server.use(
      http.get(apiUrl('/auth/me'), () =>
        HttpResponse.json({ data: sessionFixture({ is_platform_operator: true }) }),
      ),
      http.get(apiUrl('/admin/tenants/north-college'), () =>
        HttpResponse.json(
          {
            error: { code: 'not_found', message: 'No academy.', details: [], request_id: 'TEST' },
          },
          { status: 404 },
        ),
      ),
    );

    renderDetail();

    expect(await screen.findByRole('button', { name: 'Try again' })).toBeInTheDocument();
  });
});
