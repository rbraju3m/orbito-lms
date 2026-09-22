import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, courseFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { PublicCoursesRoute } from './PublicCoursesRoute';

const ACADEMY = '/public/dhaka-art-school';

function course(n: number) {
  return courseFixture({ id: `course-${n}`, slug: `course-${n}`, title: `Course ${n}` });
}

/** Serves the list and records every query string it was asked for. */
interface Serve {
  rows?: ReturnType<typeof course>[];
  total?: number;
  lastPage?: number;
  status?: number;
}

function serve({
  rows = [course(1), course(2)],
  total = rows.length,
  lastPage = 1,
  status = 200,
}: Serve = {}) {
  const asked: URLSearchParams[] = [];

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
    http.get(apiUrl(`${ACADEMY}/courses`), ({ request }) => {
      const params = new URL(request.url).searchParams;
      asked.push(params);

      if (status !== 200) {
        return HttpResponse.json(
          { error: { code: 'server_error', message: 'Boom.', details: [], request_id: 'X' } },
          { status },
        );
      }

      return HttpResponse.json({
        data: rows,
        meta: {
          current_page: Number(params.get('page') ?? 1),
          per_page: 20,
          total,
          last_page: lastPage,
        },
        links: { first: null, prev: null, next: null, last: null },
      });
    }),
  );

  return { asked, last: () => asked[asked.length - 1]! };
}

function renderPage(query = '') {
  return renderWithRouter(<PublicCoursesRoute />, {
    path: '/a/:academy/courses',
    route: `/a/dhaka-art-school/courses${query}`,
  });
}

describe('PublicCoursesRoute', () => {
  it('shows a loading state first', () => {
    serve();
    renderPage();

    expect(screen.getByRole('status', { name: /loading courses/i })).toBeInTheDocument();
  });

  it('lists the courses, each linking to its page inside the academy site', async () => {
    serve({ total: 2 });
    renderPage();

    expect(screen.getByRole('heading', { level: 1, name: 'Courses' })).toBeInTheDocument();
    const card = await screen.findByRole('link', { name: /Course 1/ });
    // Never the members-only page a stranger would be bounced off.
    expect(card).toHaveAttribute('href', '/a/dhaka-art-school/courses/course-1');
    expect(screen.getByRole('heading', { level: 2, name: 'Course 2' })).toBeInTheDocument();
    expect(screen.getByText('2 courses')).toBeInTheDocument();
  });

  it('asks for the filters in the link, and drops values the API would refuse', async () => {
    const { last } = serve();
    renderPage('?level=advanced&price=free&sort=price_asc&page=2&junk=1');

    await screen.findByText('Course 1');
    expect(Object.fromEntries(last())).toEqual({ level: 'advanced', price: 'free', page: '2' });
  });

  it('returns to the first page when the search changes', async () => {
    const { last } = serve({ total: 45, lastPage: 3 });
    const user = userEvent.setup();
    renderPage('?page=3');

    await screen.findByText('Course 1');
    expect(last().get('page')).toBe('3');

    await user.type(screen.getByRole('textbox', { name: 'Search courses' }), 'ink');

    await waitFor(() => expect(last().get('q')).toBe('ink'));
    expect(last().has('page')).toBe(false);
  });

  it('pages, and brings the reader back to the top of the list', async () => {
    const { last } = serve({ total: 45, lastPage: 3 });
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Course 1');
    await user.click(screen.getByRole('button', { name: 'Page 2' }));

    await waitFor(() => expect(last().get('page')).toBe('2'));
    expect(screen.getByRole('heading', { level: 1, name: 'Courses' })).toHaveFocus();
    expect(screen.getByRole('button', { name: 'Page 2' })).toHaveAttribute('aria-current', 'page');
  });

  it('says there is nothing yet when nothing is filtered', async () => {
    serve({ rows: [] });
    renderPage();

    expect(await screen.findByText('No courses yet')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /clear filters/i })).not.toBeInTheDocument();
  });

  it('offers a way out of a search that matches nothing', async () => {
    const { last } = serve({ rows: [] });
    const user = userEvent.setup();
    renderPage('?level=advanced');

    expect(await screen.findByText('No courses match')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /clear filters/i }));

    await waitFor(() => expect(last().has('level')).toBe(false));
  });

  it('recovers from a link to a page past the end', async () => {
    const { last } = serve({ rows: [], total: 12 });
    const user = userEvent.setup();
    renderPage('?page=9');

    expect(await screen.findByText('This page is past the end of the list')).toBeInTheDocument();
    // Not "no courses yet": there are some, just not on page 9.
    expect(screen.queryByText('No courses yet')).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Go to the first page' }));
    await waitFor(() => expect(last().has('page')).toBe(false));
  });

  it('renders an error state with a retry', async () => {
    serve({ status: 500 });
    renderPage();

    expect(await screen.findByText('The course list could not be loaded')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /try again|retry/i })).toBeInTheDocument();
  });
});
