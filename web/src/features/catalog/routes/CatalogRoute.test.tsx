import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
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

/** A list of `total` courses over `lastPage` pages, recording every query string asked. */
function servePages(total: number, lastPage: number, rows: unknown[] = [courseFixture()]) {
  const asked: URLSearchParams[] = [];
  server.use(
    http.get(apiUrl('/courses'), ({ request }) => {
      const params = new URL(request.url).searchParams;
      asked.push(params);
      return HttpResponse.json({
        data: rows,
        meta: {
          current_page: Number(params.get('page') ?? 1),
          per_page: 20,
          total,
          last_page: lastPage,
        },
        links: { first: null, prev: null, next: null, last: null },
      });
    }),
    http.get(apiUrl('/categories'), () => HttpResponse.json({ data: [] })),
  );
  return { last: () => asked[asked.length - 1]! };
}

function renderCatalog(query = '') {
  return renderWithRouter(<CatalogRoute />, { path: '/courses', route: `/courses${query}` });
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

  // It showed the first 20 and nothing said there were more.
  it('pages, and brings the reader back to the top of the list', async () => {
    const { last } = servePages(45, 3);
    const user = userEvent.setup();
    renderCatalog();

    expect(await screen.findByText('45 courses')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Page 2' }));

    await waitFor(() => expect(last().get('page')).toBe('2'));
    expect(screen.getByRole('heading', { level: 1, name: 'Courses' })).toHaveFocus();
    expect(screen.getByRole('button', { name: 'Page 2' })).toHaveAttribute('aria-current', 'page');
  });

  it('shows no pager when everything fits on one page', async () => {
    servePages(1, 1);
    renderCatalog();

    await screen.findByText('1 course');
    expect(screen.queryByRole('button', { name: 'Page 1' })).not.toBeInTheDocument();
  });

  it('returns to the first page when a filter changes', async () => {
    const { last } = servePages(45, 3);
    const user = userEvent.setup();
    renderCatalog('?page=3');

    await screen.findByText('45 courses');
    expect(last().get('page')).toBe('3');

    await user.type(screen.getByRole('textbox', { name: 'Search courses' }), 'ink');

    await waitFor(() => expect(last().get('q')).toBe('ink'));
    expect(last().has('page')).toBe(false);
  });

  it('recovers from a link to a page past the end', async () => {
    const { last } = servePages(12, 1, []);
    const user = userEvent.setup();
    renderCatalog('?page=9');

    expect(await screen.findByText('This page is past the end of the list')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Go to the first page' }));
    await waitFor(() => expect(last().has('page')).toBe(false));
  });

  // The API 422s each of these; a shared link should still open the list.
  it('drops filter values the API would refuse', async () => {
    const { last } = servePages(1, 1);
    renderCatalog('?level=expert&sort=price_asc&page=-1&category=design');

    await screen.findByText('1 course');
    expect(Object.fromEntries(last())).toEqual({ category: 'design' });
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
