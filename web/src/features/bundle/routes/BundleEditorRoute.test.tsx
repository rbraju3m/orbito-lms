import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it, vi } from 'vitest';

import { apiUrl, sessionFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { Bundle, BundleCheck } from '../api/types';
import { BundleEditorRoute } from './BundleEditorRoute';

function check(overrides: Partial<BundleCheck> = {}): BundleCheck {
  return {
    code: 'has_two_courses',
    field: 'courses',
    message: 'A bundle needs at least two courses. One course is just that course.',
    blocking: true,
    passed: false,
    ...overrides,
  };
}

function bundle(overrides: Partial<Bundle> = {}): Bundle {
  return {
    id: 'b-1',
    slug: 'draft-bundle',
    title: 'Draft bundle',
    subtitle: null,
    description: null,
    status: 'draft',
    status_label: 'Draft',
    published_at: null,
    price: null,
    courses: [],
    parts_total_minor: 0,
    checklist: [check()],
    available_actions: ['published', 'archived'],
    ...overrides,
  };
}

function serve(row: Bundle, onPatch?: (body: unknown) => void) {
  server.use(
    http.get(apiUrl('/auth/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['bundle.manage'] }) }),
    ),
    http.get(apiUrl('/studio/bundles/b-1'), () => HttpResponse.json({ data: row })),
    http.get(apiUrl('/studio/courses'), () =>
      HttpResponse.json({
        data: [
          { id: 'c1', ref: 1, slug: 'one', title: 'Course one', status: 'published' },
          { id: 'c2', ref: 2, slug: 'two', title: 'Course two', status: 'published' },
        ],
        meta: { current_page: 1, per_page: 20, total: 2, last_page: 1 },
        links: {},
      }),
    ),
    http.patch(apiUrl('/studio/bundles/b-1'), async ({ request }) => {
      onPatch?.(await request.json());
      return HttpResponse.json({ data: row });
    }),
  );
}

describe('BundleEditorRoute', () => {
  /* The author must see the blocking reasons, not just a refused button. */
  it('renders the blocking checklist reasons from the server', async () => {
    serve(bundle());
    renderWithRouter(<BundleEditorRoute />, { path: '/studio/bundles/:id', route: '/studio/bundles/b-1' });

    expect(await screen.findByText(/needs at least two courses/i)).toBeInTheDocument();
    expect(screen.getByText(/before this can be published/i)).toBeInTheDocument();
  });

  it('says so plainly when nothing is blocking', async () => {
    serve(bundle({ checklist: [check({ passed: true })] }));
    renderWithRouter(<BundleEditorRoute />, { path: '/studio/bundles/:id', route: '/studio/bundles/b-1' });

    expect(await screen.findByText(/ready to publish/i)).toBeInTheDocument();
  });

  /*
   * The WHOLE collection, never a delta: the server replaces rather than
   * merges, so two people editing one bundle cannot interleave into a set
   * neither asked for.
   */
  it('sends the whole course list on save', async () => {
    const onPatch = vi.fn();
    serve(bundle(), onPatch);
    renderWithRouter(<BundleEditorRoute />, { path: '/studio/bundles/:id', route: '/studio/bundles/b-1' });

    const user = userEvent.setup();
    await user.click(await screen.findByPlaceholderText('Pick published courses'));
    await user.click(await screen.findByText('Course one'));
    await user.click(await screen.findByText('Course two'));
    await user.click(screen.getByRole('button', { name: /^save$/i }));

    await vi.waitFor(() => expect(onPatch).toHaveBeenCalled());
    expect(onPatch.mock.calls[0]?.[0]).toMatchObject({ course_ids: [1, 2] });
  });

  it('offers unpublish rather than publish once it is live', async () => {
    serve(bundle({ status: 'published', status_label: 'Published' }));
    renderWithRouter(<BundleEditorRoute />, { path: '/studio/bundles/:id', route: '/studio/bundles/b-1' });

    expect(await screen.findByRole('button', { name: /unpublish/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^publish$/i })).not.toBeInTheDocument();
  });

  /*
   * Mantine's NumberInput reports a STRING for a half-typed value, so the
   * button must not submit one (§ Phase 7).
   */
  it('will not submit a price that is not yet a number', async () => {
    serve(bundle());
    renderWithRouter(<BundleEditorRoute />, { path: '/studio/bundles/:id', route: '/studio/bundles/b-1' });

    expect(await screen.findByRole('button', { name: /set price/i })).toBeDisabled();
  });
});
