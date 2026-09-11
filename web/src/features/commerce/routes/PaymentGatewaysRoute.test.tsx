import { screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { PaymentGatewayAccount } from '../api/types';
import { PaymentGatewaysRoute } from './PaymentGatewaysRoute';

const stripe: PaymentGatewayAccount = {
  gateway: 'stripe',
  label: 'Stripe',
  is_connected: true,
  has_webhook_secret: true,
  is_active: true,
  is_test_mode: true,
  webhook_url:
    'http://localhost:8000/api/v1/webhooks/payments/stripe/01990000-0000-7000-8000-000000000000',
  webhook_events: ['payment_intent.succeeded', 'payment_intent.payment_failed'],
  updated_at: null,
};

describe('PaymentGatewaysRoute', () => {
  /*
   * Stripe's setup asks for an endpoint URL, and the academy id in it is
   * shown nowhere else in the product. Without this the setup steps stop at
   * a box nobody can fill in.
   */
  it('shows the address the provider must send webhooks to, ready to copy', async () => {
    server.use(
      http.get(apiUrl('/admin/payment-gateways'), () => HttpResponse.json({ data: [stripe] })),
    );

    renderWithRouter(<PaymentGatewaysRoute />);

    expect(await screen.findByRole('textbox', { name: 'Stripe webhook URL' })).toHaveValue(
      stripe.webhook_url,
    );
    expect(screen.getByRole('button', { name: 'Copy Stripe webhook URL' })).toBeEnabled();
    // The events named are the server's list, not a second copy in the SPA.
    expect(screen.getByText('payment_intent.succeeded')).toBeInTheDocument();
    expect(screen.getByText('payment_intent.payment_failed')).toBeInTheDocument();
  });
});
