import { screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, courseFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { PublicCourseRoute } from './PublicCourseRoute';

const ACADEMY = '/public/dhaka-art-school';
const PATH = '/a/:academy/courses/:slug';
const ROUTE = '/a/dhaka-art-school/courses/watercolour';

function serve(options: { course?: Record<string, unknown>; registrationOpen?: boolean } = {}) {
  server.use(
    http.get(apiUrl(ACADEMY), () =>
      HttpResponse.json({
        data: {
          slug: 'dhaka-art-school',
          name: 'Dhaka Art School',
          logo_url: null,
          support_email: null,
          registration_open: options.registrationOpen ?? true,
        },
      }),
    ),
    http.get(apiUrl(`${ACADEMY}/courses/watercolour`), () =>
      HttpResponse.json({
        data: courseFixture({
          slug: 'watercolour',
          title: 'Watercolour for beginners',
          status: 'published',
          status_label: 'Published',
          ...options.course,
        }),
      }),
    ),
  );
}

describe('PublicCourseRoute', () => {
  it('renders the sales page and points a stranger at signing up', async () => {
    serve();

    renderWithRouter(<PublicCourseRoute />, { path: PATH, route: ROUTE });

    expect(
      await screen.findByRole('heading', { name: 'Watercolour for beginners' }),
    ).toBeInTheDocument();

    /*
     * The CTA carries the academy, and that is load-bearing: registration is
     * TOLD which academy to create the account in, so a link without it
     * signs somebody up into nowhere.
     */
    expect(await screen.findByRole('link', { name: /create an account to enrol/i })).toHaveAttribute(
      'href',
      '/register?academy=dhaka-art-school',
    );
  });

  it('shows a price when there is one, and Free when there is not', async () => {
    serve({
      course: {
        price: {
          product_id: 'p1',
          currency: 'BDT',
          amount_minor: 250000,
          list_amount_minor: null,
          is_on_sale: false,
        },
      },
    });

    renderWithRouter(<PublicCourseRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByText(/2,500/)).toBeInTheDocument();
  });

  it('offers no signup at all when the academy is closed', async () => {
    serve({ registrationOpen: false });

    renderWithRouter(<PublicCourseRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByText(/not taking new registrations/i)).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /create an account/i })).not.toBeInTheDocument();

    // Sign in stays: an existing member still has an account.
    expect(screen.getByRole('link', { name: /already a member/i })).toBeInTheDocument();
  });

  it('explains a course that is not on offer rather than showing an error code', async () => {
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
      http.get(apiUrl(`${ACADEMY}/courses/watercolour`), () =>
        HttpResponse.json(
          { error: { code: 'not_found', message: 'Resource not found.', details: [], request_id: 'X' } },
          { status: 404 },
        ),
      ),
    );

    renderWithRouter(<PublicCourseRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByText(/this course is not available/i)).toBeInTheDocument();
  });
});
