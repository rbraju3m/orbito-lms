import { screen, within } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, paginated } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { PagesRoute } from './PagesRoute';

describe('PagesRoute', () => {
  it('marks the front page and the pages in the header', async () => {
    const base = { block_count: 3, published_at: null, updated_at: '2026-09-10T08:00:00+00:00' };

    server.use(
      http.get(apiUrl('/admin/pages'), () =>
        HttpResponse.json(
          paginated([
            {
              ...base,
              id: 'a',
              slug: 'home',
              title: 'Welcome',
              status: 'published',
              status_label: 'Published',
              is_home: true,
              show_in_nav: false,
            },
            {
              ...base,
              id: 'b',
              slug: 'about',
              title: 'About us',
              status: 'draft',
              status_label: 'Draft',
              is_home: false,
              show_in_nav: true,
            },
          ]),
        ),
      ),
    );

    renderWithRouter(<PagesRoute />);

    const welcome = await screen.findByRole('link', { name: /Welcome/ });
    expect(within(welcome).getByText('Front page')).toBeInTheDocument();
    expect(
      within(screen.getByRole('link', { name: /About us/ })).getByText('In header'),
    ).toBeInTheDocument();
  });

  it('invites the first page when there are none', async () => {
    server.use(http.get(apiUrl('/admin/pages'), () => HttpResponse.json(paginated([]))));

    renderWithRouter(<PagesRoute />);

    expect(await screen.findByText('No pages yet')).toBeInTheDocument();
  });
});
