import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { server } from '@/shared/test/server';
import { renderWithRouter } from '@/shared/test/renderRoute';

import type { Cart } from '../api/types';
import { CartRoute } from './CartRoute';

const API = 'http://localhost:8000/api/v1';

function cart(overrides: Partial<Cart> = {}): Cart {
  return {
    id: 'cart-uuid',
    currency: 'USD',
    item_count: 1,
    estimated_total_minor: 4900,
    is_checkoutable: true,
    items: [
      {
        id: '1',
        product_id: 'product-uuid',
        title: 'Modern Bengali Poetry',
        purchasable_type: 'course',
        purchasable_id: 7,
        amount_minor: 4900,
        list_amount_minor: null,
        is_on_sale: false,
        is_available: true,
      },
    ],
    ...overrides,
  };
}

function serveCart(body: Cart) {
  server.use(http.get(`${API}/cart`, () => HttpResponse.json({ data: body })));
}

describe('CartRoute', () => {
  it('shows the lines and the total the server computed', async () => {
    serveCart(cart());
    renderWithRouter(<CartRoute />);

    expect(await screen.findByText('Modern Bengali Poetry')).toBeInTheDocument();
    expect(screen.getAllByText('$49.00').length).toBeGreaterThan(0);
    expect(screen.getByRole('button', { name: /check out/i })).toBeEnabled();
  });

  it('calls the total an estimate rather than a promise', async () => {
    // The basket is priced live; the order is priced once, at checkout. If the
    // page ever presents this figure as final, this test should fail.
    serveCart(cart());
    renderWithRouter(<CartRoute />);

    expect(await screen.findByText(/estimated total/i)).toBeInTheDocument();
    expect(screen.getByText(/confirmed when you check out/i)).toBeInTheDocument();
  });

  it('offers somewhere to go when the basket is empty', async () => {
    serveCart(cart({ item_count: 0, items: [], estimated_total_minor: 0, is_checkoutable: false }));
    renderWithRouter(<CartRoute />);

    expect(await screen.findByText(/your basket is empty/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /browse courses/i })).toBeInTheDocument();
  });

  it('blocks checkout on the servers word, and says which line is the problem', async () => {
    serveCart(
      cart({
        is_checkoutable: false,
        items: [
          {
            ...cart().items[0]!,
            amount_minor: null,
            is_available: false,
          },
        ],
      }),
    );
    renderWithRouter(<CartRoute />);

    // Both the line and the banner say it, deliberately — the line marks WHICH
    // one, the banner says what to do about it.
    expect(await screen.findAllByText(/no longer for sale/i)).toHaveLength(2);
    expect(screen.getByText(/remove it to continue/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /check out/i })).toBeDisabled();
    // An unavailable line has no price. Rendering 0 would read as free.
    expect(screen.queryByText('$0.00')).not.toBeInTheDocument();
  });

  it('surfaces a lapsed subscription as its own message, not a generic failure', async () => {
    serveCart(cart());
    server.use(
      http.post(`${API}/checkout`, () =>
        HttpResponse.json(
          {
            error: {
              code: 'subscription_lapsed',
              message: 'This academy cannot take payments right now.',
              details: [],
              request_id: 'req-1',
            },
          },
          { status: 402 },
        ),
      ),
    );

    renderWithRouter(<CartRoute />);
    await userEvent.click(await screen.findByRole('button', { name: /check out/i }));

    await waitFor(() =>
      expect(screen.getByText(/cannot take payments right now/i)).toBeInTheDocument(),
    );
  });

  it('shows an error state rather than an empty basket when the request fails', async () => {
    server.use(http.get(`${API}/cart`, () => HttpResponse.error()));
    renderWithRouter(<CartRoute />);

    expect(await screen.findByText(/something went wrong/i)).toBeInTheDocument();
  });
});
