import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it, vi } from 'vitest';

import { apiUrl } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { WebhookEndpoint } from '../api/types';
import { endpointFixture, TOPICS } from '../test/fixtures';
import { WebhooksRoute } from './WebhooksRoute';

function serve(rows: WebhookEndpoint[]) {
  server.use(
    http.get(apiUrl('/admin/webhooks'), () =>
      HttpResponse.json({
        data: rows,
        meta: { current_page: 1, per_page: 20, total: rows.length, last_page: 1, topics: TOPICS },
        links: { first: null, prev: null, next: null, last: null },
      }),
    ),
  );
}

describe('WebhooksRoute', () => {
  it('lists endpoints and says which are switched off, and why', async () => {
    serve([
      endpointFixture(),
      endpointFixture({
        id: 'ep-2',
        url: 'https://old.example.com/in',
        is_active: false,
        disabled_reason: 'Switched off automatically after 5 deliveries in a row failed every attempt.',
        events: ['enrollment.created', 'course.completed'],
      }),
    ]);
    renderWithRouter(<WebhooksRoute />);

    expect(await screen.findByRole('link', { name: 'https://hooks.example.com/orbito' })).toBeInTheDocument();
    expect(screen.getByText('Switched off')).toBeInTheDocument();
    expect(screen.getByText(/5 deliveries in a row/)).toBeInTheDocument();
    expect(screen.getByText(/2 events/)).toBeInTheDocument();
  });

  it('explains an empty list and offers to add one', async () => {
    serve([]);
    renderWithRouter(<WebhooksRoute />);

    expect(await screen.findByText('No endpoints yet')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Add endpoint' })).toBeInTheDocument();
  });

  it('creates an endpoint and reveals its secret once, then opens it', async () => {
    const posted = vi.fn();
    serve([]);
    server.use(
      http.post(apiUrl('/admin/webhooks'), async ({ request }) => {
        posted(await request.json());
        return HttpResponse.json(
          { data: { ...endpointFixture({ id: 'ep-new' }), secret: 'whsec_abc123' } },
          { status: 201 },
        );
      }),
    );
    renderWithRouter(<WebhooksRoute />);

    await userEvent.click(await screen.findByRole('button', { name: 'Add endpoint' }));
    await userEvent.type(screen.getByLabelText(/^URL/), 'https://hooks.example.com/orbito');
    await userEvent.click(screen.getByLabelText('Enrolled'));
    await userEvent.click(screen.getByRole('button', { name: 'Create endpoint' }));

    await waitFor(() =>
      expect(posted).toHaveBeenCalledWith({
        url: 'https://hooks.example.com/orbito',
        description: null,
        events: ['enrollment.created'],
      }),
    );

    expect(await screen.findByDisplayValue('whsec_abc123')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'I have saved it' }));

    // Onward to the new endpoint's page — outside this test's route table.
    expect(await screen.findByTestId('elsewhere')).toBeInTheDocument();
  });

  it('asks for at least one event before sending anything', async () => {
    const posted = vi.fn();
    serve([]);
    server.use(http.post(apiUrl('/admin/webhooks'), () => posted()));
    renderWithRouter(<WebhooksRoute />);

    await userEvent.click(await screen.findByRole('button', { name: 'Add endpoint' }));
    await userEvent.type(screen.getByLabelText(/^URL/), 'https://hooks.example.com/orbito');
    await userEvent.click(screen.getByRole('button', { name: 'Create endpoint' }));

    expect(await screen.findByText('Pick at least one event.')).toBeInTheDocument();
    expect(posted).not.toHaveBeenCalled();
  });

  /* Whether an address is safe depends on DNS, so only the server can say. */
  it('shows why the server refused the address', async () => {
    serve([]);
    server.use(
      http.post(apiUrl('/admin/webhooks'), () =>
        HttpResponse.json(
          {
            error: {
              code: 'webhook_target_refused',
              message:
                'crm.internal points at a private or internal address, which webhooks are never sent to.',
              details: [],
            },
          },
          { status: 422 },
        ),
      ),
    );
    renderWithRouter(<WebhooksRoute />);

    await userEvent.click(await screen.findByRole('button', { name: 'Add endpoint' }));
    await userEvent.type(screen.getByLabelText(/^URL/), 'https://crm.internal/in');
    await userEvent.click(screen.getByLabelText('Enrolled'));
    await userEvent.click(screen.getByRole('button', { name: 'Create endpoint' }));

    expect(await screen.findByRole('alert')).toHaveTextContent(/private or internal address/);
  });
});
