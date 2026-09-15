import { screen, waitFor } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { PublicPageRoute } from './PublicPageRoute';

const ACADEMY = '/public/dhaka-art-school';
const PATH = '/a/:academy/p/:slug';
const ROUTE = '/a/dhaka-art-school/p/about';

function serve(status = 200) {
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
    http.get(apiUrl(`${ACADEMY}/pages/about`), () =>
      status === 200
        ? HttpResponse.json({
            data: {
              id: 'pg-1',
              slug: 'about',
              title: 'About us',
              seo_title: null,
              seo_description: 'Who we are and how we teach.',
              blocks: [{ id: 'b1', type: 'heading', props: { text: 'Who we are', level: 2 } }],
            },
          })
        : HttpResponse.json(
            { error: { code: 'not_found', message: 'Not found.', details: [], request_id: 'r' } },
            { status },
          ),
    ),
  );
}

describe('PublicPageRoute', () => {
  it('draws the page and names the tab after it', async () => {
    serve();

    renderWithRouter(<PublicPageRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByRole('heading', { name: 'Who we are' })).toBeInTheDocument();
    await waitFor(() => expect(document.title).toBe('About us — Dhaka Art School'));
  });

  it('says a page is not available rather than showing nothing', async () => {
    serve(404);

    renderWithRouter(<PublicPageRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByText('This page is not available')).toBeInTheDocument();
  });
});
