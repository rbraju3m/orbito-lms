import { screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, sessionFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { Download } from '../api/types';
import { DownloadEditorRoute } from './DownloadEditorRoute';

function draft(overrides: Partial<Download> = {}): Download {
  return {
    id: 'd-1',
    slug: 'untitled',
    title: 'Untitled download',
    subtitle: null,
    description: null,
    status: 'draft',
    status_label: 'Draft',
    published_at: null,
    pricing_model: 'one_time',
    is_free: false,
    file: null,
    price: null,
    can_fetch: true,
    checklist: [
      { code: 'file_attached', field: 'media_id', message: 'Upload the file buyers will receive.', blocking: true, passed: false },
    ],
    available_actions: ['published', 'archived'],
    ...overrides,
  };
}

function serve(row: Download) {
  server.use(
    http.get(apiUrl('/auth/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['download.manage'] }) }),
    ),
    http.get(apiUrl('/studio/downloads/d-1'), () => HttpResponse.json({ data: row })),
  );
}

const route = { path: '/studio/downloads/:id', route: '/studio/downloads/d-1' };

describe('DownloadEditorRoute', () => {
  it('names what blocks publication, from the server', async () => {
    serve(draft());
    renderWithRouter(<DownloadEditorRoute />, route);

    expect(await screen.findByText(/upload the file buyers will receive/i)).toBeInTheDocument();
    expect(screen.getByText(/no file yet/i)).toBeInTheDocument();
  });

  /* A free download has no product, so there is nothing to price. */
  it('shows the price field only for a paid download', async () => {
    serve(draft({ pricing_model: 'free', is_free: true }));
    renderWithRouter(<DownloadEditorRoute />, route);

    expect(await screen.findByText(/free to members/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /set price/i })).not.toBeInTheDocument();
  });

  /* A half-typed NumberInput reports a string, and must not be submitted (§ Phase 7). */
  it('will not submit a price that is not yet a number', async () => {
    serve(draft());
    renderWithRouter(<DownloadEditorRoute />, route);

    expect(await screen.findByRole('button', { name: /set price/i })).toBeDisabled();
  });
});
