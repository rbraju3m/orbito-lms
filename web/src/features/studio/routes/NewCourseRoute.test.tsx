import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, sessionFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { NewCourseRoute } from './NewCourseRoute';

function serve(permissions: string[]) {
  server.use(
    http.get(apiUrl('/auth/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions }) }),
    ),
    http.get(apiUrl('/categories'), () => HttpResponse.json({ data: [] })),
    http.post(apiUrl('/studio/courses'), () =>
      HttpResponse.json(
        {
          error: {
            code: 'plan_limit_reached',
            message: 'Your plan includes 3 courses. Upgrading adds room for more.',
            details: [{ code: 'courses_total', message: 'Courses: 3 of 3 used.' }],
            meta: { metric: 'courses_total', limit: 3, used: 3, plan: 'Starter' },
            request_id: 'req_1',
          },
        },
        { status: 402 },
      ),
    ),
  );
}

async function submit() {
  const user = userEvent.setup();
  await user.type(await screen.findByLabelText(/course title/i), 'A fourth course');
  await user.click(screen.getByRole('button', { name: /create/i }));
}

describe('NewCourseRoute', () => {
  /*
   * A plan limit is not a form error — nothing the author retypes will fix
   * it — so it must not land on the title field.
   */
  it('explains a plan limit instead of failing the title field', async () => {
    serve(['course.create']);
    renderWithRouter(<NewCourseRoute />);

    await submit();

    expect(await screen.findByRole('alert')).toHaveTextContent(/plan includes 3 courses/i);
    expect(screen.getByLabelText(/course title/i)).toBeValid();
  });

  /* An instructor cannot read the usage screen, so they get the ask instead. */
  it('tells an instructor who to ask rather than linking a screen they cannot open', async () => {
    serve(['course.create']);
    renderWithRouter(<NewCourseRoute />);

    await submit();

    expect(await screen.findByText(/ask an academy administrator/i)).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /plan and usage/i })).not.toBeInTheDocument();
  });

  it('links an admin straight to the usage screen', async () => {
    serve(['course.create', 'settings.view']);
    renderWithRouter(<NewCourseRoute />);

    await submit();

    expect(await screen.findByRole('link', { name: /plan and usage/i })).toHaveAttribute(
      'href',
      '/admin/plan',
    );
  });
});
