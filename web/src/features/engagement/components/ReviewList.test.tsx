import { screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { Review } from '../api/types';
import { ReviewList } from './ReviewList';

const API = 'http://localhost:8000/api/v1';

function review(overrides: Partial<Review> = {}): Review {
  return {
    id: 'review-uuid',
    rating: 4,
    title: 'Solid',
    body: '<p>Clear and well paced.</p>',
    status: 'published',
    status_label: 'Published',
    is_published: true,
    author: { name: 'Rumi Haque', is_you: false },
    instructor_reply: null,
    replied_at: null,
    published_at: '2026-09-01T10:00:00+00:00',
    created_at: '2026-09-01T10:00:00+00:00',
    updated_at: '2026-09-01T10:00:00+00:00',
    ...overrides,
  };
}

function serve(rows: Review[], canReview: boolean) {
  server.use(
    http.get(`${API}/courses/:id/reviews`, () =>
      HttpResponse.json({
        data: rows,
        meta: {
          current_page: 1,
          per_page: 15,
          total: rows.length,
          last_page: 1,
          can_review: canReview,
        },
        links: { first: null, prev: null, next: null, last: null },
      }),
    ),
  );
}

describe('ReviewList', () => {
  it('offers the form only when the server says the write would be accepted', async () => {
    /*
     * `can_review` is computed from the two conditions SubmitReview itself
     * enforces. Offering a form the server will refuse is the dead end this
     * codebase keeps declining to ship.
     */
    serve([review()], false);
    renderWithRouter(<ReviewList courseId="course-uuid" />);

    expect(await screen.findByText('Solid')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /write a review/i })).not.toBeInTheDocument();
  });

  it('offers it when they took the course', async () => {
    serve([review()], true);
    renderWithRouter(<ReviewList courseId="course-uuid" />);

    expect(await screen.findByRole('button', { name: /write a review/i })).toBeInTheDocument();
  });

  it('says a pending review is pending rather than letting it look lost', async () => {
    /*
     * Discovering your review silently vanished is worse than being told it
     * is waiting on a moderator — which is why the API returns the caller's
     * own review whatever its status.
     */
    serve(
      [
        review({
          status: 'pending',
          status_label: 'Pending',
          is_published: false,
          author: { name: 'You', is_you: true },
        }),
      ],
      true,
    );
    renderWithRouter(<ReviewList courseId="course-uuid" />);

    expect(await screen.findByText('Pending')).toBeInTheDocument();
    // And the button offers to replace it, not to write a second one.
    expect(screen.getByRole('button', { name: /edit your review/i })).toBeInTheDocument();
  });

  it('explains an empty list differently to somebody who could fill it', async () => {
    serve([], true);
    renderWithRouter(<ReviewList courseId="course-uuid" />);

    expect(await screen.findByText('No reviews yet')).toBeInTheDocument();
    expect(screen.getByText(/be the first/i)).toBeInTheDocument();
  });
});
