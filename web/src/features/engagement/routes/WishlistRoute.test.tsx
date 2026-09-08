import { screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { WishlistRoute } from './WishlistRoute';

const API = 'http://localhost:8000/api/v1';

const savedCourse = {
  saved_at: '2026-09-01T10:00:00+00:00',
  course: {
    id: 'course-uuid',
    slug: 'modern-bengali-poetry',
    title: 'Modern Bengali Poetry',
    subtitle: 'From Tagore onward',
    status: 'published',
    status_label: 'Published',
    level: 'beginner',
    level_label: 'Beginner',
    locale: 'en',
    pricing_model: 'free',
    price: null,
    thumbnail_url: null,
    category: null,
    item_count: 4,
    section_count: 1,
    total_duration_seconds: 900,
    enrollment_count: 12,
    rating_avg: 4.5,
    rating_count: 2,
    published_at: '2026-08-01T10:00:00+00:00',
  },
};

function serve(rows: unknown[]) {
  server.use(
    http.get(`${API}/wishlist`, () =>
      HttpResponse.json({
        data: rows,
        meta: { current_page: 1, per_page: 20, total: rows.length, last_page: 1 },
        links: { first: null, prev: null, next: null, last: null },
      }),
    ),
  );
}

describe('WishlistRoute', () => {
  it('renders a saved course as the same card the catalogue shows', async () => {
    serve([savedCourse]);
    renderWithRouter(<WishlistRoute />);

    expect(await screen.findByText('Modern Bengali Poetry')).toBeInTheDocument();
    // The card, price included — a saved course and a browsed one look alike.
    expect(screen.getByText('Free')).toBeInTheDocument();
  });

  it('explains that the list empties itself', async () => {
    /*
     * Enrolling removes the entry server-side, so there is no "enrolled"
     * state to render here — an enrolled course is simply not on the list.
     */
    serve([]);
    renderWithRouter(<WishlistRoute />);

    expect(await screen.findByText('Nothing saved yet')).toBeInTheDocument();
    expect(screen.getByText(/until you enrol/i)).toBeInTheDocument();
  });
});
