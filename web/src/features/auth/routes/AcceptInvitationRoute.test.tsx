import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it, vi } from 'vitest';

import { apiUrl, sessionFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { AcceptInvitationRoute } from './AcceptInvitationRoute';

const LINK = '?academy=north-college&token=the-token';

function renderAt(search: string) {
  return renderWithRouter(<AcceptInvitationRoute />, { path: '/invite', route: `/invite${search}` });
}

function refusal(code: string, message: string, status: number) {
  return HttpResponse.json(
    { error: { code, message, details: [], request_id: 'TEST' } },
    { status },
  );
}

function servePreview(respond?: () => Response) {
  const bodies: unknown[] = [];

  server.use(
    http.post(apiUrl('/public/north-college/invitations/show'), async ({ request }) => {
      bodies.push(await request.json());
      return (
        respond?.() ??
        HttpResponse.json({
          data: {
            email: 'grace@example.test',
            role: 'instructor',
            role_label: 'Instructor',
            academy_name: 'North College',
            expires_at: '2026-10-06T00:00:00Z',
          },
        })
      );
    }),
  );

  return bodies;
}

async function fillTheForm() {
  await userEvent.type(await screen.findByLabelText(/Full name/), 'Grace Hopper');
  await userEvent.type(screen.getByLabelText(/^Password/), 'correct horse battery');
  await userEvent.type(screen.getByLabelText(/Confirm password/), 'correct horse battery');
}

describe('AcceptInvitationRoute', () => {
  it('says what the link is for, and POSTs the token rather than putting it in a URL', async () => {
    const bodies = servePreview();

    renderAt(LINK);

    expect(await screen.findByRole('heading', { name: 'Join North College' })).toBeInTheDocument();
    expect(screen.getByText(/invited as an instructor/)).toBeInTheDocument();
    // Shown, not asked for: the account takes the invited address.
    expect(screen.getByLabelText(/^Email/)).toHaveValue('grace@example.test');
    expect(screen.getByLabelText(/^Email/)).toHaveAttribute('readonly');
    expect(bodies).toEqual([{ token: 'the-token' }]);
  });

  it('creates the account with the academy and token from the link', async () => {
    servePreview();
    const posted = vi.fn();
    server.use(
      http.post(apiUrl('/auth/invitations/accept'), async ({ request }) => {
        posted(await request.json());
        return HttpResponse.json({ data: sessionFixture() }, { status: 201 });
      }),
    );

    renderAt(LINK);
    await fillTheForm();
    await userEvent.click(screen.getByRole('button', { name: 'Create account' }));

    expect(posted).toHaveBeenCalledWith({
      academy: 'north-college',
      token: 'the-token',
      name: 'Grace Hopper',
      password: 'correct horse battery',
      password_confirmation: 'correct horse battery',
    });
  });

  it('tells the holder of an expired link to ask for another, instead of a form', async () => {
    servePreview(() =>
      refusal('invitation_expired', 'This invitation has expired. Ask the academy to send you a new one.', 410),
    );

    renderAt(LINK);

    expect(await screen.findByRole('heading', { name: 'This invitation cannot be used' })).toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent(/Ask the academy to send you a new one/);
    expect(screen.queryByRole('button', { name: 'Create account' })).not.toBeInTheDocument();
  });

  it('sends somebody whose link was already used to sign in', async () => {
    servePreview(() =>
      refusal('invitation_accepted', 'This invitation has already been used. Sign in with the account it created.', 410),
    );

    renderAt(LINK);

    expect(await screen.findByRole('heading', { name: 'You already have an account' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Sign in' })).toHaveAttribute('href', '/login');
  });

  it('answers a refusal at submit time above the form, with a way to sign in', async () => {
    servePreview();
    server.use(
      http.post(apiUrl('/auth/invitations/accept'), () =>
        refusal('account_exists', 'An account with this email address already exists. Sign in instead.', 409),
      ),
    );

    renderAt(LINK);
    await fillTheForm();
    await userEvent.click(screen.getByRole('button', { name: 'Create account' }));

    expect(await screen.findByText(/already exists/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Sign in' })).toBeInTheDocument();
  });

  it('explains an incomplete link without asking the server', async () => {
    const bodies = servePreview();

    renderAt('?academy=north-college');

    expect(await screen.findByText(/link is incomplete/)).toBeInTheDocument();
    expect(bodies).toEqual([]);
  });
});
