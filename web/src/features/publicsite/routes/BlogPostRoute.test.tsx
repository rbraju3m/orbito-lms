import { screen, waitFor } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { BlogPostRoute } from './BlogPostRoute';

const ACADEMY = '/public/dhaka-art-school';
const PATH = '/a/:academy/blog/:slug';
const ROUTE = '/a/dhaka-art-school/blog/why-watercolour';

function post(overrides: Record<string, unknown> = {}) {
  return {
    id: 'p-1',
    slug: 'why-watercolour',
    title: 'Why watercolour',
    excerpt: 'Where every beginner should start.',
    body: '<h2>Start wet</h2><p>Paper first.</p>',
    cover_url: null,
    author: { name: 'Nusrat Jahan' },
    published_at: '2026-09-10T08:00:00+00:00',
    reading_minutes: 4,
    seo_title: null,
    seo_description: null,
    ...overrides,
  };
}

function serve(overrides: Record<string, unknown> = {}, status = 200) {
  server.use(
    http.get(apiUrl(ACADEMY), () =>
      HttpResponse.json({
        data: {
          slug: 'dhaka-art-school',
          name: 'Dhaka Art School',
          logo_url: null,
          support_email: null,
          registration_open: true,
        },
      }),
    ),
    http.get(apiUrl(`${ACADEMY}/posts/why-watercolour`), () =>
      status === 200
        ? HttpResponse.json({ data: post(overrides) })
        : HttpResponse.json(
            { error: { code: 'not_found', message: 'Not found.', details: [], request_id: 'r' } },
            { status },
          ),
    ),
  );
}

describe('BlogPostRoute', () => {
  it('renders the post and its saved HTML', async () => {
    serve();

    renderWithRouter(<BlogPostRoute />, { path: PATH, route: ROUTE });

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Why watercolour' }),
    ).toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 2, name: 'Start wet' })).toBeInTheDocument();
    expect(screen.getByText(/4 min read/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /all posts/i })).toHaveAttribute(
      'href',
      '/a/dhaka-art-school/blog',
    );
  });

  it('names the tab and the shared link from the search fields first', async () => {
    serve({
      seo_title: 'Watercolour, the right way round',
      seo_description: 'Wet paper, then paint.',
    });

    renderWithRouter(<BlogPostRoute />, { path: PATH, route: ROUTE });

    await waitFor(() =>
      expect(document.title).toBe('Watercolour, the right way round — Dhaka Art School'),
    );
    expect(document.querySelector('meta[name="description"]')).toHaveAttribute(
      'content',
      'Wet paper, then paint.',
    );
  });

  it('says a post is not available rather than showing a blank page', async () => {
    serve({}, 404);

    renderWithRouter(<BlogPostRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByText('This post is not available')).toBeInTheDocument();
  });
});
