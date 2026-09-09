import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it, vi } from 'vitest';

import { apiUrl, sessionFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { RegisterRoute } from './RegisterRoute';

function renderAt(search: string) {
  return renderWithRouter(<RegisterRoute />, { path: '/register', route: `/register${search}` });
}

async function fillTheForm() {
  await userEvent.type(screen.getByLabelText(/Full name/), 'Ada Lovelace');
  await userEvent.type(screen.getByLabelText(/^Email/), 'ada@example.com');
  await userEvent.type(screen.getByLabelText(/^Password/), 'correct horse battery');
  await userEvent.type(screen.getByLabelText(/Confirm password/), 'correct horse battery');
}

/*
 * An account belongs to exactly one academy, and tenancy resolves from the
 * authenticated user — which a signup does not have. The academy therefore has
 * to travel in the link, and these are the tests for what happens when it does
 * and when it does not.
 */
describe('RegisterRoute', () => {
  it('sends the academy from the link', async () => {
    const posted = vi.fn();

    server.use(
      http.post(apiUrl('/auth/register'), async ({ request }) => {
        posted(await request.json());
        return HttpResponse.json({ data: sessionFixture() }, { status: 201 });
      }),
    );

    renderAt('?academy=north-college');
    await fillTheForm();
    await userEvent.click(screen.getByRole('button', { name: 'Create account' }));

    expect(posted).toHaveBeenCalledWith(expect.objectContaining({ academy: 'north-college' }));
  });

  /*
   * Said before the form is offered rather than after a post comes back 422 on
   * a field the user cannot see, let alone fix.
   */
  it('explains the missing link instead of offering a form that cannot work', async () => {
    renderAt('');

    expect(await screen.findByText(/need an invitation link/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Create account' })).not.toBeInTheDocument();
  });

  it('shows the server’s refusal when the academy is not taking sign-ups', async () => {
    server.use(
      http.post(apiUrl('/auth/register'), () =>
        HttpResponse.json(
          {
            error: {
              code: 'registration_not_open',
              message: 'This academy does not accept sign-ups.',
              details: [],
              request_id: 'TEST',
              meta: { registration_mode: 'closed' },
            },
          },
          { status: 403 },
        ),
      ),
    );

    renderAt('?academy=north-college');
    await fillTheForm();
    await userEvent.click(screen.getByRole('button', { name: 'Create account' }));

    expect(await screen.findByRole('alert')).toHaveTextContent(/does not accept sign-ups/);
  });
});
