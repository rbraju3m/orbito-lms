import { screen, within } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, paginated } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { PostsRoute } from './PostsRoute';

describe('PostsRoute', () => {
  it('tells a draft, a scheduled post and a live one apart', async () => {
    const base = {
      excerpt: null,
      cover_url: null,
      author: { name: 'Admin' },
      reading_minutes: 2,
      updated_at: '2026-09-10T08:00:00+00:00',
    };

    server.use(
      http.get(apiUrl('/admin/posts'), () =>
        HttpResponse.json(
          paginated([
            {
              ...base,
              id: 'a',
              slug: 'a',
              title: 'Half written',
              status: 'draft',
              status_label: 'Draft',
              is_scheduled: false,
              published_at: null,
            },
            {
              ...base,
              id: 'b',
              slug: 'b',
              title: 'Friday post',
              status: 'published',
              status_label: 'Published',
              is_scheduled: true,
              published_at: '2026-12-01T08:00:00+00:00',
            },
            {
              ...base,
              id: 'c',
              slug: 'c',
              title: 'Out already',
              status: 'published',
              status_label: 'Published',
              is_scheduled: false,
              published_at: '2026-09-01T08:00:00+00:00',
            },
          ]),
        ),
      ),
    );

    renderWithRouter(<PostsRoute />);

    expect(await screen.findByText('Half written')).toBeInTheDocument();
    // Each badge inside its own card: the filter above also says "Published".
    expect(
      within(screen.getByRole('link', { name: /Half written/ })).getByText('Draft'),
    ).toBeInTheDocument();
    expect(
      within(screen.getByRole('link', { name: /Friday post/ })).getByText('Scheduled'),
    ).toBeInTheDocument();
    expect(
      within(screen.getByRole('link', { name: /Out already/ })).getByText('Published'),
    ).toBeInTheDocument();
  });

  it('invites the first post when there are none', async () => {
    server.use(http.get(apiUrl('/admin/posts'), () => HttpResponse.json(paginated([]))));

    renderWithRouter(<PostsRoute />);

    expect(await screen.findByText('No posts yet')).toBeInTheDocument();
  });
});
