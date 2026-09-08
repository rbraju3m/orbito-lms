import { screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import type { CoursePrice } from '@/features/catalog/api/types';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { Cart } from '../api/types';
import { BuyPanel } from './BuyPanel';

const API = 'http://localhost:8000/api/v1';

const emptyCart: Cart = {
  id: 'cart-uuid',
  currency: 'USD',
  item_count: 0,
  estimated_total_minor: 0,
  is_checkoutable: false,
  items: [],
};

function price(overrides: Partial<CoursePrice> = {}): CoursePrice {
  return {
    product_id: 'product-uuid',
    currency: 'USD',
    amount_minor: 4900,
    list_amount_minor: null,
    is_on_sale: false,
    ...overrides,
  };
}

function serveCart(body: Cart = emptyCart) {
  server.use(http.get(`${API}/cart`, () => HttpResponse.json({ data: body })));
}

describe('BuyPanel', () => {
  it('names the price on the button screen', async () => {
    serveCart();
    renderWithRouter(<BuyPanel price={price()} courseTitle="A course" />);

    expect(await screen.findByText('$49.00')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /buy this course/i })).toBeInTheDocument();
  });

  it('shows the usual price struck through during a sale', async () => {
    serveCart();
    renderWithRouter(
      <BuyPanel
        price={price({ amount_minor: 2900, list_amount_minor: 9900, is_on_sale: true })}
        courseTitle="A course"
      />,
    );

    expect(await screen.findByText('$29.00')).toBeInTheDocument();
    expect(screen.getByText('$99.00')).toBeInTheDocument();
  });

  it('does not call an unbuyable course free', async () => {
    /*
     * A paid course with no price is NOT free — its product was deactivated,
     * or it is not sold in this currency. Saying "Free" here would be a lie
     * the checkout would then refuse.
     */
    serveCart();
    renderWithRouter(<BuyPanel price={null} courseTitle="A course" />);

    expect(await screen.findByText(/not available/i)).toBeInTheDocument();
    expect(screen.queryByText(/free/i)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /buy/i })).not.toBeInTheDocument();
  });

  it('reads the basket from the cache rather than remembering its own copy', async () => {
    serveCart({
      ...emptyCart,
      item_count: 1,
      items: [
        {
          id: '1',
          product_id: 'product-uuid',
          title: 'A course',
          purchasable_type: 'course',
          purchasable_id: 1,
          amount_minor: 4900,
          list_amount_minor: null,
          is_on_sale: false,
          is_available: true,
        },
      ],
    });

    renderWithRouter(<BuyPanel price={price()} courseTitle="A course" />);

    expect(await screen.findByText(/in your basket/i)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /go to basket/i })).toBeInTheDocument();
  });

  it('explains a gate instead of disabling the button silently', async () => {
    serveCart();
    renderWithRouter(
      <BuyPanel
        price={price()}
        courseTitle="A course"
        disabled
        disabledReason="Finish “Intro” first."
      />,
    );

    expect(await screen.findByRole('button', { name: /buy this course/i })).toBeDisabled();
    expect(screen.getByText(/finish “intro” first/i)).toBeInTheDocument();
  });
});
