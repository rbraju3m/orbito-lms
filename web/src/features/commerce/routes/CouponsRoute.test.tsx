import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it, vi } from 'vitest';

import { apiUrl } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { Coupon } from '../api/coupons';
import { CouponsRoute } from './CouponsRoute';

function coupon(overrides: Partial<Coupon> = {}): Coupon {
  return {
    id: 'c-1',
    code: 'LAUNCH20',
    description: 'Launch week',
    discount_type: 'percent',
    percent_off: 20,
    amount_off_minor: null,
    currency: null,
    applies_to_all: true,
    products: [],
    min_subtotal_minor: null,
    max_redemptions: 100,
    max_redemptions_per_user: null,
    starts_at: null,
    ends_at: null,
    is_active: true,
    state: 'active',
    state_label: 'Active',
    times_used: 4,
    created_at: '2026-09-10T00:00:00Z',
    ...overrides,
  };
}

const page = (rows: Coupon[]) => ({
  data: rows,
  meta: { current_page: 1, per_page: 20, total: rows.length, last_page: 1 },
  links: { first: null, prev: null, next: null, last: null },
});

function serve(rows: Coupon[]) {
  server.use(
    http.get(apiUrl('/admin/coupons'), () => HttpResponse.json(page(rows))),
    http.get(apiUrl('/admin/coupons/products'), () =>
      HttpResponse.json(page([]) as unknown as Record<string, unknown>),
    ),
  );
}

describe('CouponsRoute', () => {
  it('lists coupons with what they are worth and how much is left', async () => {
    serve([
      coupon(),
      coupon({
        id: 'c-2',
        code: 'OLD',
        percent_off: 10,
        max_redemptions: null,
        times_used: 0,
        state: 'expired',
        state_label: 'Expired',
      }),
    ]);
    renderWithRouter(<CouponsRoute />);

    expect(await screen.findByText('LAUNCH20')).toBeInTheDocument();
    expect(screen.getByText('20% off · Everything')).toBeInTheDocument();
    expect(screen.getByText(/Used 4 of 100 times/)).toBeInTheDocument();
    expect(screen.getByText('Expired')).toBeInTheDocument();
  });

  it('explains an empty list and offers to create one', async () => {
    serve([]);
    renderWithRouter(<CouponsRoute />);

    expect(await screen.findByText('No coupons yet')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'New coupon' })).toBeInTheDocument();
  });

  it('creates a fixed-amount coupon, sending minor units', async () => {
    const posted = vi.fn();
    serve([]);
    server.use(
      http.post(apiUrl('/admin/coupons'), async ({ request }) => {
        posted(await request.json());
        return HttpResponse.json({ data: coupon() }, { status: 201 });
      }),
    );
    renderWithRouter(<CouponsRoute />);

    await userEvent.click(await screen.findByRole('button', { name: 'New coupon' }));
    await userEvent.type(screen.getByLabelText(/^Code/), 'SPRING');
    await userEvent.click(screen.getByRole('radio', { name: 'Fixed amount' }));
    await userEvent.type(screen.getByLabelText('Amount off'), '12.5');
    await userEvent.type(screen.getByLabelText(/^Currency/), 'bdt');
    await userEvent.click(screen.getByRole('button', { name: 'Save coupon' }));

    await waitFor(() => expect(posted).toHaveBeenCalled());
    expect(posted.mock.calls[0]![0]).toMatchObject({
      code: 'SPRING',
      discount_type: 'fixed',
      amount_off_minor: 1250,
      percent_off: null,
      currency: 'BDT',
      applies_to_all: true,
    });
  });

  it('asks for a currency before sending a fixed amount', async () => {
    const posted = vi.fn();
    serve([]);
    server.use(http.post(apiUrl('/admin/coupons'), () => posted()));
    renderWithRouter(<CouponsRoute />);

    await userEvent.click(await screen.findByRole('button', { name: 'New coupon' }));
    await userEvent.type(screen.getByLabelText(/^Code/), 'SPRING');
    await userEvent.click(screen.getByRole('radio', { name: 'Fixed amount' }));
    await userEvent.type(screen.getByLabelText('Amount off'), '5');
    await userEvent.click(screen.getByRole('button', { name: 'Save coupon' }));

    expect(await screen.findByText('A three-letter currency code, like BDT.')).toBeInTheDocument();
    expect(posted).not.toHaveBeenCalled();
  });

  /* A used coupon is part of somebody's receipt: the server says so. */
  it('shows why a coupon cannot be deleted', async () => {
    serve([coupon()]);
    server.use(
      http.delete(apiUrl('/admin/coupons/c-1'), () =>
        HttpResponse.json(
          {
            error: {
              code: 'coupon_in_use',
              message: 'This coupon is on 4 orders, so it cannot be deleted. Switch it off instead.',
              details: [],
            },
          },
          { status: 409 },
        ),
      ),
    );
    renderWithRouter(<CouponsRoute />);

    await userEvent.click(await screen.findByRole('button', { name: 'Delete LAUNCH20' }));
    await userEvent.click(screen.getByRole('button', { name: 'Delete' }));

    expect(await screen.findByRole('alert')).toHaveTextContent(/Switch it off instead/);
  });
});
