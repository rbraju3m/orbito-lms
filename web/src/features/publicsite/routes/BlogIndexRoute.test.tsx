import { screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, paginated } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { BlogIndexRoute } from './BlogIndexRoute';

const ACADEMY = '/public/dhaka-art-school';
const PATH = '/a/:academy/blog';
const ROUTE = '/a/dhaka-art-school/blog';

function serve(posts: unknown[]) {
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
    http.get(apiUrl(`${ACADEMY}/posts`), () => HttpResponse.json(paginated(posts))),
  );
}

describe('BlogIndexRoute', () => {
  it('lists posts with a link to each', async () => {
    serve([
      {
        id: 'p-1',
        slug: 'why-watercolour',
        title: 'Why watercolour',
        excerpt: 'Where every beginner should start.',
        cover_url: null,
        author: { name: 'Nusrat Jahan' },
        published_at: '2026-09-10T08:00:00+00:00',
        reading_minutes: 4,
      },
    ]);

    renderWithRouter(<BlogIndexRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByRole('link', { name: 'Why watercolour' })).toHaveAttribute(
      'href',
      '/a/dhaka-art-school/blog/why-watercolour',
    );
    expect(screen.getByText('Where every beginner should start.')).toBeInTheDocument();
  });

  it('says so when nothing has been published', async () => {
    serve([]);

    renderWithRouter(<BlogIndexRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByText('No posts yet')).toBeInTheDocument();
  });
});
