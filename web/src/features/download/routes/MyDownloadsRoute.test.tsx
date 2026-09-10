import { screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, sessionFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { DownloadListItem } from '../api/types';
import { MyDownloadsRoute } from './MyDownloadsRoute';

function owned(id: string, title: string): DownloadListItem {
  return {
    id,
    slug: id,
    title,
    subtitle: null,
    status: 'published',
    status_label: 'Published',
    published_at: '2026-09-01T00:00:00Z',
    pricing_model: 'free',
    is_free: true,
    file: { name: `${id}.zip`, mime: 'application/zip', extension: 'zip', size_bytes: 5_000 },
  };
}

function serve(rows: DownloadListItem[]) {
  server.use(
    http.get(apiUrl('/auth/me'), () => HttpResponse.json({ data: sessionFixture({}) })),
    http.get(apiUrl('/downloads/mine'), () =>
      HttpResponse.json({
        data: rows,
        meta: { current_page: 1, per_page: 20, total: rows.length, last_page: 1 },
        links: {},
      }),
    ),
  );
}

describe('MyDownloadsRoute', () => {
  it('points somewhere useful when the reader owns nothing', async () => {
    serve([]);
    renderWithRouter(<MyDownloadsRoute />);

    expect(await screen.findByText(/no downloads yet/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /browse downloads/i })).toBeInTheDocument();
  });

  it('lists every owned download with its own download button', async () => {
    serve([owned('templates', 'Template pack'), owned('audio', 'Audio series')]);
    renderWithRouter(<MyDownloadsRoute />);

    expect(await screen.findByText('Template pack')).toBeInTheDocument();
    expect(screen.getByText('Audio series')).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: /^download$/i })).toHaveLength(2);
  });
});
