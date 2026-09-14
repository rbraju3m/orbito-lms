import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { AdminPost } from '../api/postTypes';
import { PostEditorRoute } from './PostEditorRoute';

const PATH = '/admin/posts/:id';
const ROUTE = '/admin/posts/p-1';

function post(overrides: Partial<AdminPost> = {}): AdminPost {
  return {
    id: 'p-1',
    slug: 'untitled-post',
    title: 'Untitled post',
    excerpt: null,
    body: null,
    cover_url: null,
    author: { name: 'Admin' },
    published_at: null,
    reading_minutes: 1,
    seo_title: null,
    seo_description: null,
    status: 'draft',
    status_label: 'Draft',
    is_scheduled: false,
    can_edit_slug: true,
    cover_media_ref: null,
    created_at: '2026-09-10T08:00:00+00:00',
    updated_at: '2026-09-10T08:00:00+00:00',
    ...overrides,
  };
}

describe('PostEditorRoute', () => {
  it('saves what was typed and shows the version the server stored', async () => {
    let sent: Record<string, unknown> | null = null;
    server.use(
      http.get(apiUrl('/admin/posts/p-1'), () => HttpResponse.json({ data: post() })),
      http.patch(apiUrl('/admin/posts/p-1'), async ({ request }) => {
        sent = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({
          data: post({
            title: 'Why watercolour',
            slug: 'why-watercolour',
            body: '<p>Start wet.</p>',
          }),
        });
      }),
    );
    const user = userEvent.setup();

    renderWithRouter(<PostEditorRoute />, { path: PATH, route: ROUTE });

    const title = await screen.findByRole('textbox', { name: /^title/i });
    await user.clear(title);
    await user.type(title, 'Why watercolour');
    const address = screen.getByRole('textbox', { name: /address/i });
    await user.clear(address);
    await user.type(address, 'why-watercolour');
    await user.type(
      screen.getByRole('textbox', { name: 'Body' }),
      '<p>Start wet.</p><script>x</script>',
    );
    await user.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(sent).not.toBeNull());
    expect(sent).toMatchObject({ title: 'Why watercolour', slug: 'why-watercolour' });

    // The preview is the SAVED body — sanitised — not what was typed.
    await user.click(screen.getByRole('radio', { name: 'Preview' }));
    expect(await screen.findByText('Start wet.')).toBeInTheDocument();
  });

  it('does not offer to change the address of a post that has been out', async () => {
    server.use(
      http.get(apiUrl('/admin/posts/p-1'), () =>
        HttpResponse.json({
          data: post({
            status: 'published',
            status_label: 'Published',
            published_at: '2026-09-01T08:00:00+00:00',
            can_edit_slug: false,
            body: '<p>Out.</p>',
          }),
        }),
      ),
    );

    renderWithRouter(<PostEditorRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByRole('textbox', { name: /address/i })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Unpublish' })).toBeInTheDocument();
  });

  it('explains why an empty post cannot be published', async () => {
    server.use(
      http.get(apiUrl('/admin/posts/p-1'), () => HttpResponse.json({ data: post() })),
      http.post(apiUrl('/admin/posts/p-1/publish'), () =>
        HttpResponse.json(
          {
            error: {
              code: 'post_not_publishable',
              message: 'A post needs something in it before it can be published.',
              details: [],
              request_id: 'r',
            },
          },
          { status: 422 },
        ),
      ),
    );
    const user = userEvent.setup();

    renderWithRouter(<PostEditorRoute />, { path: PATH, route: ROUTE });

    await user.click(await screen.findByRole('button', { name: 'Publish now' }));

    expect(await screen.findByText(/needs something in it/)).toBeInTheDocument();
  });
});
