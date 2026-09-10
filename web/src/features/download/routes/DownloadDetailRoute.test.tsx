import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { apiUrl, sessionFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { Download } from '../api/types';
import { DownloadDetailRoute } from './DownloadDetailRoute';

function download(overrides: Partial<Download> = {}): Download {
  return {
    id: 'd-1',
    slug: 'workbook',
    title: 'The complete workbook',
    subtitle: 'Every exercise, in one file',
    description: 'Forty pages of exercises.',
    status: 'published',
    status_label: 'Published',
    published_at: '2026-09-01T00:00:00Z',
    pricing_model: 'one_time',
    is_free: false,
    file: { name: 'workbook.pdf', mime: 'application/pdf', extension: 'pdf', size_bytes: 2 * 1024 * 1024 },
    price: { product_id: 'prod-1', currency: 'USD', amount_minor: 1500, list_amount_minor: null, is_on_sale: false },
    can_fetch: false,
    ...overrides,
  };
}

function serve(row: Download, extra: Parameters<typeof server.use> = []) {
  server.use(
    http.get(apiUrl('/auth/me'), () => HttpResponse.json({ data: sessionFixture({}) })),
    http.get(apiUrl('/downloads/workbook'), () => HttpResponse.json({ data: row })),
    ...extra,
  );
}

const route = { path: '/downloads/:slug', route: '/downloads/workbook' };

describe('DownloadDetailRoute', () => {
  const realLocation = window.location;

  afterEach(() => {
    Object.defineProperty(window, 'location', { value: realLocation, writable: true });
  });

  it('offers a paid download to a non-owner at its price', async () => {
    serve(download());
    renderWithRouter(<DownloadDetailRoute />, route);

    expect(await screen.findByText('$15.00')).toBeInTheDocument();
    expect(screen.getByText('PDF · 2.0 MB')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /add to basket/i })).toBeEnabled();
  });

  it('offers a free download to be claimed, not bought', async () => {
    const claim = vi.fn();
    serve(download({ is_free: true, pricing_model: 'free', price: null }), [
      http.post(apiUrl('/downloads/workbook/claim'), () => {
        claim();
        return HttpResponse.json({ data: download({ is_free: true, can_fetch: true }) });
      }),
    ]);
    renderWithRouter(<DownloadDetailRoute />, route);

    await userEvent.setup().click(await screen.findByRole('button', { name: /get it free/i }));

    await vi.waitFor(() => expect(claim).toHaveBeenCalledOnce());
  });

  /*
   * A fresh link every time, fetched on click and never cached — a stale one
   * handed out after its fifteen minutes would simply fail.
   */
  it('mints a fresh link when an owner downloads', async () => {
    const assign = vi.fn();
    Object.defineProperty(window, 'location', { value: { ...realLocation, assign }, writable: true });

    serve(download({ can_fetch: true }), [
      http.get(apiUrl('/downloads/workbook/file'), () =>
        HttpResponse.json({ data: { url: 'https://files.test/signed', expires_at: '2026-09-16T12:15:00Z' } }),
      ),
    ]);
    renderWithRouter(<DownloadDetailRoute />, route);

    await userEvent.setup().click(await screen.findByRole('button', { name: /^download$/i }));

    await vi.waitFor(() => expect(assign).toHaveBeenCalledWith('https://files.test/signed'));
  });

  /* Archiving takes it off sale, never out of an owner's hands — and says so. */
  it('tells an owner an archived download is still theirs', async () => {
    serve(download({ status: 'archived', status_label: 'Archived', can_fetch: true }));
    renderWithRouter(<DownloadDetailRoute />, route);

    expect(await screen.findByText(/no longer on sale\. you still own it/i)).toBeInTheDocument();
  });

  /* Null price is "not buyable right now", not free. */
  it('will not sell a paid download that has no price on sale', async () => {
    serve(download({ price: null }));
    renderWithRouter(<DownloadDetailRoute />, route);

    expect(await screen.findByText(/not available right now/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /add to basket/i })).toBeDisabled();
  });
});
