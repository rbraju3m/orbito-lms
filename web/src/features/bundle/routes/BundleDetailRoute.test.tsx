import { screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, sessionFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { Bundle } from '../api/types';
import { BundleDetailRoute } from './BundleDetailRoute';

function course(ref: number, title: string, minor: number) {
  return {
    id: `course-${ref}`,
    ref,
    slug: `course-${ref}`,
    title,
    subtitle: null,
    level: 'all',
    level_label: 'All levels',
    locale: 'en',
    status: 'published',
    status_label: 'Published',
    visibility: 'public',
    pricing_model: 'one_time',
    price: {
      product_id: `p-${ref}`,
      currency: 'USD',
      amount_minor: minor,
      list_amount_minor: null,
      is_on_sale: false,
    },
    item_count: 3,
    total_duration_seconds: 0,
    enrollment_count: 0,
    rating_avg: 0,
  };
}

function bundle(overrides: Partial<Bundle> = {}): Bundle {
  return {
    id: 'b-1',
    slug: 'complete-path',
    title: 'The complete path',
    subtitle: 'Everything, in order',
    description: 'Two courses that belong together.',
    status: 'published',
    status_label: 'Published',
    published_at: '2026-09-01T00:00:00Z',
    course_count: 2,
    price: {
      product_id: 'prod-bundle',
      currency: 'USD',
      amount_minor: 4500,
      list_amount_minor: null,
      is_on_sale: false,
    },
    courses: [course(1, 'First course', 2000), course(2, 'Second course', 4000)] as never,
    parts_total_minor: 6000,
    owned_course_ids: [],
    ...overrides,
  };
}

function serve(row: Bundle) {
  server.use(
    http.get(apiUrl('/auth/me'), () => HttpResponse.json({ data: sessionFixture({}) })),
    http.get(apiUrl('/bundles/complete-path'), () => HttpResponse.json({ data: row })),
  );
}

describe('BundleDetailRoute', () => {
  /* The saving is the whole argument for buying a bundle. */
  it('shows the price against what the courses cost separately', async () => {
    serve(bundle());
    renderWithRouter(<BundleDetailRoute />, { path: '/bundles/:slug', route: '/bundles/complete-path' });

    expect(await screen.findByText('$45.00')).toBeInTheDocument();
    expect(screen.getByText(/\$60\.00 bought separately/)).toBeInTheDocument();
    expect(screen.getByText(/you save \$15\.00/i)).toBeInTheDocument();
  });

  /*
   * Partial overlap does not block the sale, so the page owes them a plain
   * statement of what is new BEFORE they pay rather than a surprise after.
   */
  it('says what is new when the buyer already owns part of it', async () => {
    serve(bundle({ owned_course_ids: [1] }));
    renderWithRouter(<BundleDetailRoute />, { path: '/bundles/:slug', route: '/bundles/complete-path' });

    expect(await screen.findByText(/already have 1 of these 2 courses/i)).toBeInTheDocument();
    expect(screen.getByText('Owned')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /add to basket/i })).toBeEnabled();
  });

  it('cannot be bought when every course is already owned', async () => {
    serve(bundle({ owned_course_ids: [1, 2] }));
    renderWithRouter(<BundleDetailRoute />, { path: '/bundles/:slug', route: '/bundles/complete-path' });

    expect(await screen.findByText(/already own every course/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /add to basket/i })).toBeDisabled();
  });

  /* Null price is "not buyable right now", not free. */
  it('does not offer a basket button when the bundle is not for sale', async () => {
    serve(bundle({ price: null }));
    renderWithRouter(<BundleDetailRoute />, { path: '/bundles/:slug', route: '/bundles/complete-path' });

    expect(await screen.findByText(/not available right now/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /add to basket/i })).toBeDisabled();
  });

  it('lists every course in the bundle', async () => {
    serve(bundle());
    renderWithRouter(<BundleDetailRoute />, { path: '/bundles/:slug', route: '/bundles/complete-path' });

    expect(await screen.findByText('First course')).toBeInTheDocument();
    expect(screen.getByText('Second course')).toBeInTheDocument();
  });
});
