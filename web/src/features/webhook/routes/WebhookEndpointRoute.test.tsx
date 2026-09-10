import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it, vi } from 'vitest';

import { apiUrl } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { WebhookDelivery, WebhookEndpoint } from '../api/types';
import { deliveryFixture, endpointFixture, TOPICS } from '../test/fixtures';
import { WebhookEndpointRoute } from './WebhookEndpointRoute';

function serve(endpoint: WebhookEndpoint, deliveries: WebhookDelivery[] = []) {
  server.use(
    http.get(apiUrl(`/admin/webhooks/${endpoint.id}`), () => HttpResponse.json({ data: endpoint })),
    http.get(apiUrl('/admin/webhooks'), () =>
      HttpResponse.json({
        data: [endpoint],
        meta: { current_page: 1, per_page: 20, total: 1, last_page: 1, topics: TOPICS },
        links: { first: null, prev: null, next: null, last: null },
      }),
    ),
    http.get(apiUrl(`/admin/webhooks/${endpoint.id}/deliveries`), () =>
      HttpResponse.json({
        data: deliveries,
        meta: { current_page: 1, per_page: 20, total: deliveries.length, last_page: 1 },
        links: { first: null, prev: null, next: null, last: null },
      }),
    ),
  );
}

const renderEndpoint = (id = 'ep-1') =>
  renderWithRouter(<WebhookEndpointRoute />, {
    route: `/admin/webhooks/${id}`,
    path: '/admin/webhooks/:endpointId',
  });

describe('WebhookEndpointRoute', () => {
  it('says why an endpoint was switched off, and will not test it', async () => {
    serve(
      endpointFixture({
        is_active: false,
        disabled_reason: 'Switched off automatically after 5 deliveries in a row failed every attempt.',
      }),
    );
    renderEndpoint();

    expect(await screen.findByText(/5 deliveries in a row/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Send test event/ })).toBeDisabled();
  });

  it('explains an empty delivery log', async () => {
    serve(endpointFixture());
    renderEndpoint();

    expect(await screen.findByText('Nothing sent yet')).toBeInTheDocument();
  });

  it('sends a test event', async () => {
    const tested = vi.fn();
    serve(endpointFixture());
    server.use(
      http.post(apiUrl('/admin/webhooks/ep-1/test'), () => {
        tested();
        return HttpResponse.json(
          { data: deliveryFixture({ topic: 'ping', status: 'pending', attempts: 0 }) },
          { status: 202 },
        );
      }),
    );
    renderEndpoint();

    await userEvent.click(await screen.findByRole('button', { name: /Send test event/ }));

    await waitFor(() => expect(tested).toHaveBeenCalledOnce());
    expect(await screen.findByRole('status')).toHaveTextContent(/Test event queued/);
  });

  it('shows what was sent and what the receiver said, and redelivers', async () => {
    const redelivered = vi.fn();
    serve(endpointFixture(), [deliveryFixture()]);
    server.use(
      http.post(apiUrl('/admin/webhooks/ep-1/deliveries/dl-1/redeliver'), () => {
        redelivered();
        return HttpResponse.json({ data: deliveryFixture({ id: 'dl-2' }) }, { status: 202 });
      }),
    );
    renderEndpoint();

    expect(await screen.findByText('HTTP 503 · 120 ms')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Details' }));
    expect(screen.getByText('The receiver answered 503.')).toBeInTheDocument();
    expect(screen.getByText(/"Rahima"/)).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Redeliver enrollment.created' }));
    await waitFor(() => expect(redelivered).toHaveBeenCalledOnce());
  });

  it('rotates the secret only after confirming, and reveals the new one', async () => {
    serve(endpointFixture());
    server.use(
      http.post(apiUrl('/admin/webhooks/ep-1/rotate-secret'), () =>
        HttpResponse.json({ data: { ...endpointFixture(), secret: 'whsec_rotated' } }),
      ),
    );
    renderEndpoint();

    await userEvent.click(await screen.findByRole('button', { name: 'Rotate secret' }));
    expect(screen.getByText(/stops working immediately/)).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Rotate' }));

    expect(await screen.findByDisplayValue('whsec_rotated')).toBeInTheDocument();
  });

  it('switches an endpoint off', async () => {
    const patched = vi.fn();
    serve(endpointFixture());
    server.use(
      http.patch(apiUrl('/admin/webhooks/ep-1'), async ({ request }) => {
        patched(await request.json());
        return HttpResponse.json({
          data: endpointFixture({ is_active: false, disabled_reason: 'Switched off by an administrator.' }),
        });
      }),
    );
    renderEndpoint();

    await userEvent.click(await screen.findByLabelText('Sending events'));

    await waitFor(() => expect(patched).toHaveBeenCalledWith({ is_active: false }));
    expect(await screen.findByText(/Switched off by an administrator/)).toBeInTheDocument();
  });
});
