import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it, vi } from 'vitest';

import { apiUrl } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { RefundReport } from '../api/refundReports';
import { RefundReportsRoute } from './RefundReportsRoute';

function report(overrides: Partial<RefundReport> = {}): RefundReport {
  return {
    id: 7,
    gateway: 'stripe',
    gateway_label: 'Stripe',
    event_type: 'refund.updated',
    received_at: '2026-09-12T08:00:00Z',
    items: [
      {
        reason: 'more_than_left',
        reason_label: 'More than the order has left to refund',
        advice:
          'Usually a refund made in the provider’s dashboard that was also recorded here by hand.',
        provider_refund_id: 're_double',
        amount_minor: 4900,
        currency: 'USD',
        reported_status: 'completed',
        reported_status_label: 'Refunded',
      },
    ],
    order: { id: 'order-1', number: '20260911-ABCDEFGH' },
    resolved_at: null,
    resolution_note: null,
    ...overrides,
  };
}

const page = (rows: RefundReport[]) => ({
  data: rows,
  meta: { current_page: 1, per_page: 20, total: rows.length, last_page: 1 },
  links: { first: null, prev: null, next: null, last: null },
});

describe('RefundReportsRoute', () => {
  it('says so when nothing needs a person', async () => {
    server.use(http.get(apiUrl('/admin/refund-reports'), () => HttpResponse.json(page([]))));

    renderWithRouter(<RefundReportsRoute />);

    expect(await screen.findByText('Nothing needs you')).toBeInTheDocument();
  });

  /*
   * The words are the server's — what happened and what to check — so the
   * screen cannot drift from the rule that raised the report.
   */
  it('shows what happened, what to check, and the order it concerns', async () => {
    server.use(http.get(apiUrl('/admin/refund-reports'), () => HttpResponse.json(page([report()]))));

    renderWithRouter(<RefundReportsRoute />);

    expect(await screen.findByText('More than the order has left to refund')).toBeInTheDocument();
    expect(screen.getByText(/also recorded here by hand/)).toBeInTheDocument();
    expect(screen.getByText(/Stripe reported \$49\.00/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Order 20260911-ABCDEFGH' })).toHaveAttribute(
      'href',
      '/orders/order-1',
    );
  });

  it('marks a report resolved with a note, and the list re-reads', async () => {
    const posted = vi.fn();
    let open = [report()];

    server.use(
      http.get(apiUrl('/admin/refund-reports'), () => HttpResponse.json(page(open))),
      http.post(apiUrl('/admin/refund-reports/7/resolve'), async ({ request }) => {
        posted(await request.json());
        open = [];
        return HttpResponse.json({
          data: report({ resolved_at: '2026-09-12T09:00:00Z', resolution_note: 'Nothing owed.' }),
        });
      }),
    );

    renderWithRouter(<RefundReportsRoute />);

    await userEvent.click(await screen.findByRole('button', { name: 'Mark resolved' }));
    await userEvent.type(screen.getByLabelText(/What did you do/), 'Nothing owed.');
    await userEvent.click(screen.getByRole('button', { name: 'Resolve' }));

    await waitFor(() => expect(posted).toHaveBeenCalledWith({ note: 'Nothing owed.' }));
    expect(await screen.findByText('Nothing needs you')).toBeInTheDocument();
  });
});
