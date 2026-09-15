import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { AdminPage, PageBlock } from '../api/pageTypes';
import { PageBuilderRoute } from './PageBuilderRoute';

const PATH = '/admin/pages/:id';
const ROUTE = '/admin/pages/pg-1';

function page(overrides: Partial<AdminPage> = {}): AdminPage {
  return {
    id: 'pg-1',
    slug: 'about',
    title: 'About',
    blocks: [],
    seo_title: null,
    seo_description: null,
    status: 'draft',
    status_label: 'Draft',
    published_at: null,
    is_home: false,
    show_in_nav: false,
    can_edit_slug: true,
    created_at: '2026-09-10T08:00:00+00:00',
    updated_at: '2026-09-10T08:00:00+00:00',
    ...overrides,
  };
}

const heading = (id: string, text: string): PageBlock => ({
  id,
  type: 'heading',
  props: { text, level: 2 },
});

describe('PageBuilderRoute', () => {
  it('adds a heading, fills it in, and saves the whole list', async () => {
    const bodies: Array<{ blocks: Array<{ type: string; props: Record<string, unknown> }> }> = [];
    server.use(
      http.get(apiUrl('/admin/pages/pg-1'), () => HttpResponse.json({ data: page() })),
      http.put(apiUrl('/admin/pages/pg-1/blocks'), async ({ request }) => {
        bodies.push((await request.json()) as (typeof bodies)[number]);
        return HttpResponse.json({
          data: page({ blocks: [heading('saved', 'About the school')] }),
        });
      }),
    );
    const user = userEvent.setup();

    renderWithRouter(<PageBuilderRoute />, { path: PATH, route: ROUTE });

    await user.click(await screen.findByRole('button', { name: 'Add block' }));
    await user.click(await screen.findByRole('menuitem', { name: /Heading/ }));
    await user.type(screen.getByRole('textbox', { name: 'Heading' }), 'About the school');
    await user.click(screen.getByRole('button', { name: 'Save blocks' }));

    await waitFor(() => expect(bodies).toHaveLength(1));
    expect(bodies[0]?.blocks).toHaveLength(1);
    expect(bodies[0]?.blocks[0]).toMatchObject({
      type: 'heading',
      props: { text: 'About the school', level: 2 },
    });
  });

  it('reorders without dragging, and sends the new order whole', async () => {
    const bodies: Array<{ blocks: Array<{ id: string }> }> = [];
    server.use(
      http.get(apiUrl('/admin/pages/pg-1'), () =>
        HttpResponse.json({
          data: page({ blocks: [heading('first', 'First'), heading('second', 'Second')] }),
        }),
      ),
      http.put(apiUrl('/admin/pages/pg-1/blocks'), async ({ request }) => {
        bodies.push((await request.json()) as (typeof bodies)[number]);
        return HttpResponse.json({
          data: page({ blocks: [heading('second', 'Second'), heading('first', 'First')] }),
        });
      }),
    );
    const user = userEvent.setup();

    renderWithRouter(<PageBuilderRoute />, { path: PATH, route: ROUTE });

    await user.click(await screen.findByRole('button', { name: 'Move Second up' }));
    await user.click(screen.getByRole('button', { name: 'Save blocks' }));

    await waitFor(() => expect(bodies).toHaveLength(1));
    expect(bodies[0]?.blocks.map((block) => block.id)).toEqual(['second', 'first']);
  });

  it('says which block the server refused', async () => {
    server.use(
      http.get(apiUrl('/admin/pages/pg-1'), () =>
        HttpResponse.json({ data: page({ blocks: [heading('first', 'First')] }) }),
      ),
      http.put(apiUrl('/admin/pages/pg-1/blocks'), () =>
        HttpResponse.json(
          {
            error: {
              code: 'validation_failed',
              message: 'The given data was invalid.',
              details: [
                {
                  field: 'blocks.1.props.url',
                  code: 'regex',
                  message: 'That link cannot be used.',
                },
              ],
              request_id: 'r',
            },
          },
          { status: 422 },
        ),
      ),
    );
    const user = userEvent.setup();

    renderWithRouter(<PageBuilderRoute />, { path: PATH, route: ROUTE });

    await user.click(await screen.findByRole('button', { name: 'Add block' }));
    await user.click(await screen.findByRole('menuitem', { name: /Button/ }));
    await user.click(screen.getByRole('button', { name: 'Save blocks' }));

    expect(await screen.findByText('Block 2: That link cannot be used.')).toBeInTheDocument();
  });

  it('explains why an empty page cannot be published', async () => {
    server.use(
      http.get(apiUrl('/admin/pages/pg-1'), () => HttpResponse.json({ data: page() })),
      http.post(apiUrl('/admin/pages/pg-1/publish'), () =>
        HttpResponse.json(
          {
            error: {
              code: 'page_not_publishable',
              message: 'A page needs at least one block before it can be published.',
              details: [],
              request_id: 'r',
            },
          },
          { status: 422 },
        ),
      ),
    );
    const user = userEvent.setup();

    renderWithRouter(<PageBuilderRoute />, { path: PATH, route: ROUTE });

    await user.click(await screen.findByRole('button', { name: 'Publish' }));

    expect(await screen.findByText(/at least one block/)).toBeInTheDocument();
  });
});
