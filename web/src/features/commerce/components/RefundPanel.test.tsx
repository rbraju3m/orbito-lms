import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it, vi } from 'vitest';

import { apiUrl, sessionFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { Order, Refund } from '../api/types';
import { RefundPanel } from './RefundPanel';

function order(overrides: Partial<Order> = {}): Order {
  return {
    id: 'order-1',
    number: '20260911-ABCDEFGH',
    status: 'paid',
    status_label: 'Paid',
    grants_access: true,
    currency: 'USD',
    coupon_code: null,
    subtotal_minor: 4900,
    discount_minor: 0,
    total_minor: 4900,
    refunded_minor: 0,
    refundable_minor: 4900,
    placed_at: '2026-09-10T09:00:00Z',
    paid_at: '2026-09-10T09:01:00Z',
    cancelled_at: null,
    items: [],
    refunds: [],
    ...overrides,
  };
}

function refundRow(overrides: Partial<Refund> = {}): Refund {
  return {
    id: 'r-1',
    amount_minor: 1000,
    currency: 'USD',
    method: 'gateway',
    method_label: 'Through the payment provider',
    status: 'completed',
    status_label: 'Refunded',
    reason: 'Charged twice.',
    revokes_access: false,
    failure_reason: null,
    completed_at: '2026-09-11T10:00:00Z',
    created_at: '2026-09-11T10:00:00Z',
    ...overrides,
  };
}

function asStaff(permissions: string[]) {
  server.use(http.get(apiUrl('/auth/me'), () => HttpResponse.json({ data: sessionFixture({ permissions }) })));
}

describe('RefundPanel', () => {
  it('shows the learner what was given back, with no way to give more', async () => {
    asStaff([]);
    renderWithRouter(<RefundPanel order={order({ refundable_minor: 3900, refunds: [refundRow()] })} />);

    expect(await screen.findByText('$10.00')).toBeInTheDocument();
    expect(screen.getByText(/Charged twice/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Refund/ })).not.toBeInTheDocument();
  });

  it('refunds everything left by default, and takes access away unless told not to', async () => {
    const posted = vi.fn();
    asStaff(['order.refund']);
    server.use(
      http.post(apiUrl('/admin/orders/order-1/refunds'), async ({ request }) => {
        posted(await request.json());
        return HttpResponse.json({ data: refundRow({ amount_minor: 4900 }) }, { status: 201 });
      }),
    );
    renderWithRouter(<RefundPanel order={order()} />);

    await userEvent.click(await screen.findByRole('button', { name: /Refund…/ }));
    expect(screen.getByLabelText(/Take away the courses/)).toBeChecked();

    await userEvent.click(screen.getByRole('button', { name: /Refund \$49\.00/ }));

    await waitFor(() =>
      expect(posted).toHaveBeenCalledWith({
        amount_minor: 4900,
        method: 'gateway',
        reason: null,
        revoke_access: true,
      }),
    );
  });

  it('never offers to take access away on a partial refund', async () => {
    const posted = vi.fn();
    asStaff(['order.refund']);
    server.use(
      http.post(apiUrl('/admin/orders/order-1/refunds'), async ({ request }) => {
        posted(await request.json());
        return HttpResponse.json({ data: refundRow() }, { status: 201 });
      }),
    );
    renderWithRouter(<RefundPanel order={order()} />);

    await userEvent.click(await screen.findByRole('button', { name: /Refund…/ }));
    const amount = screen.getByLabelText(/^Amount/);
    await userEvent.clear(amount);
    await userEvent.type(amount, '10');

    expect(screen.queryByLabelText(/Take away the courses/)).not.toBeInTheDocument();
    expect(screen.getByText('A partial refund leaves access as it is.')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('radio', { name: 'Refunded elsewhere' }));
    await userEvent.click(screen.getByRole('button', { name: /Refund \$10\.00/ }));

    await waitFor(() =>
      expect(posted).toHaveBeenCalledWith(
        expect.objectContaining({ amount_minor: 1000, method: 'external', revoke_access: false }),
      ),
    );
  });

  /*
   * A refund made in the provider's dashboard arrives by webhook. Recording it
   * here as well would count the same money twice, so the form says so at the
   * moment somebody reaches for "Refunded elsewhere".
   */
  it('warns that a dashboard refund arrives by itself', async () => {
    asStaff(['order.refund']);
    renderWithRouter(<RefundPanel order={order()} />);

    await userEvent.click(await screen.findByRole('button', { name: /Refund…/ }));
    expect(screen.queryByText(/arrive here by themselves/)).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole('radio', { name: 'Refunded elsewhere' }));

    expect(
      screen.getByText(/arrive here by themselves; recording one as well would count it twice/),
    ).toBeInTheDocument();
  });

  it('shows why the provider refused', async () => {
    asStaff(['order.refund']);
    server.use(
      http.post(apiUrl('/admin/orders/order-1/refunds'), () =>
        HttpResponse.json(
          {
            error: {
              code: 'gateway_unavailable',
              message: 'The stripe gateway refused the request: Charge already refunded.',
              details: [],
            },
          },
          { status: 503 },
        ),
      ),
    );
    renderWithRouter(<RefundPanel order={order()} />);

    await userEvent.click(await screen.findByRole('button', { name: /Refund…/ }));
    await userEvent.click(screen.getByRole('button', { name: /Refund \$49\.00/ }));

    expect(await screen.findByRole('alert')).toHaveTextContent(/Charge already refunded/);
  });
});
