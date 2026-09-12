import { screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, courseFixture, paginated } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { AcademyHomeRoute } from './AcademyHomeRoute';

const ACADEMY = '/public/dhaka-art-school';
const PATH = '/a/:academy';
const ROUTE = '/a/dhaka-art-school';

function academy(overrides: Record<string, unknown> = {}) {
  return {
    slug: 'dhaka-art-school',
    name: 'Dhaka Art School',
    logo_url: null,
    support_email: 'hello@example.test',
    registration_open: true,
    ...overrides,
  };
}

function serve(options: { courses?: unknown[]; webinars?: unknown[]; site?: Record<string, unknown> } = {}) {
  server.use(
    http.get(apiUrl(ACADEMY), () => HttpResponse.json({ data: academy(options.site) })),
    http.get(apiUrl(`${ACADEMY}/courses`), () => HttpResponse.json(paginated(options.courses ?? []))),
    http.get(apiUrl(`${ACADEMY}/webinars`), () =>
      HttpResponse.json(paginated(options.webinars ?? [])),
    ),
  );
}

describe('AcademyHomeRoute', () => {
  it('wears the academy name and lists its courses', async () => {
    serve({
      courses: [
        courseFixture({ status: 'published', status_label: 'Published', title: 'Watercolour' }),
      ],
    });

    renderWithRouter(<AcademyHomeRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByRole('heading', { name: 'Dhaka Art School' })).toBeInTheDocument();
    expect(await screen.findByText('Watercolour')).toBeInTheDocument();
  });

  it('says so when nothing is published, rather than showing an empty grid', async () => {
    serve();

    renderWithRouter(<AcademyHomeRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByText(/no courses yet/i)).toBeInTheDocument();
  });

  it('does not invite a visitor to sign up when the academy is closed', async () => {
    serve({ site: { registration_open: false } });

    renderWithRouter(<AcademyHomeRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByText(/registration is currently closed/i)).toBeInTheDocument();
  });

  it('explains an academy that cannot be found instead of an error code', async () => {
    server.use(
      http.get(apiUrl(ACADEMY), () =>
        HttpResponse.json(
          { error: { code: 'not_found', message: 'Resource not found.', details: [], request_id: 'X' } },
          { status: 404 },
        ),
      ),
      http.get(apiUrl(`${ACADEMY}/courses`), () => HttpResponse.json(paginated([]))),
      http.get(apiUrl(`${ACADEMY}/webinars`), () => HttpResponse.json(paginated([]))),
    );

    renderWithRouter(<AcademyHomeRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByText(/may have moved or closed/i)).toBeInTheDocument();
  });

  it('keeps the courses when only the events panel fails', async () => {
    server.use(
      http.get(apiUrl(ACADEMY), () => HttpResponse.json({ data: academy() })),
      http.get(apiUrl(`${ACADEMY}/courses`), () =>
        HttpResponse.json(
          paginated([courseFixture({ status: 'published', title: 'Still here' })]),
        ),
      ),
      http.get(apiUrl(`${ACADEMY}/webinars`), () =>
        HttpResponse.json(
          { error: { code: 'server_error', message: 'Boom.', details: [], request_id: 'X' } },
          { status: 500 },
        ),
      ),
    );

    renderWithRouter(<AcademyHomeRoute />, { path: PATH, route: ROUTE });

    // A marketing page that blanks itself because one panel failed is worse
    // than one that shows most of itself.
    expect(await screen.findByText('Still here')).toBeInTheDocument();
  });
});
