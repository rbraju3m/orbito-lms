import { screen, waitFor } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, courseFixture, paginated } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { CatalogRoute } from './CatalogRoute';

function listHandler(rows: unknown[]) {
  return [
    http.get(apiUrl('/courses'), () => HttpResponse.json(paginated(rows))),
    http.get(apiUrl('/categories'), () => HttpResponse.json({ data: [] })),
  ];
}

describe('CatalogRoute', () => {
  it('shows a loading state first', () => {
    server.use(...listHandler([]));
    renderWithRouter(<CatalogRoute />, { path: '/courses', route: '/courses' });

    expect(screen.getByRole('status', { name: /loading/i })).toBeInTheDocument();
  });

  it('renders published courses', async () => {
    server.use(
      ...listHandler([
        courseFixture({ status: 'published', status_label: 'Published', title: 'Course One' }),
        courseFixture({ id: 'two', slug: 'two', title: 'Course Two', status: 'published' }),
      ]),
    );

    renderWithRouter(<CatalogRoute />, { path: '/courses', route: '/courses' });

    expect(await screen.findByText('Course One')).toBeInTheDocument();
    expect(screen.getByText('Course Two')).toBeInTheDocument();
  });

  it('offers a way out of an empty result', async () => {
    server.use(...listHandler([]));
    renderWithRouter(<CatalogRoute />, { path: '/courses', route: '/courses?level=advanced' });

    expect(await screen.findByText(/no courses match these filters/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /clear filters/i })).toBeInTheDocument();
  });

  it('renders an error state with a retry', async () => {
    server.use(
      http.get(apiUrl('/courses'), () =>
        HttpResponse.json(
          { error: { code: 'server_error', message: 'Boom.', details: [], request_id: 'X' } },
          { status: 500 },
        ),
      ),
      http.get(apiUrl('/categories'), () => HttpResponse.json({ data: [] })),
    );

    renderWithRouter(<CatalogRoute />, { path: '/courses', route: '/courses' });

    await waitFor(() =>
      expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument(),
    );
  });

  it('sends the URL filters to the API', async () => {
    let requested: URL | null = null;
    server.use(
      http.get(apiUrl('/courses'), ({ request }) => {
        requested = new URL(request.url);
        return HttpResponse.json(paginated([]));
      }),
      http.get(apiUrl('/categories'), () => HttpResponse.json({ data: [] })),
    );

    renderWithRouter(<CatalogRoute />, {
      path: '/courses',
      route: '/courses?level=beginner&sort=newest',
    });

    // Filters live in the URL so a filtered catalogue is shareable.
    await waitFor(() => expect(requested).not.toBeNull());
    expect(requested!.searchParams.get('level')).toBe('beginner');
    expect(requested!.searchParams.get('sort')).toBe('newest');
  });
});
