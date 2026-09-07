import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, sessionFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { LoginRoute } from './LoginRoute';

function renderLogin() {
  return renderWithRouter(<LoginRoute />, { path: '/login', route: '/login' });
}

describe('LoginRoute', () => {
  it('validates before hitting the network', async () => {
    const user = userEvent.setup();
    renderLogin();

    await user.click(screen.getByRole('button', { name: /sign in/i }));

    expect(await screen.findByText(/email is required/i)).toBeInTheDocument();
    expect(screen.getByText(/password is required/i)).toBeInTheDocument();
  });

  it('signs in and navigates away', async () => {
    server.use(
      http.post(apiUrl('/auth/login'), () => HttpResponse.json({ data: sessionFixture() })),
    );

    const user = userEvent.setup();
    renderLogin();

    await user.type(screen.getByLabelText(/email/i), 'ada@example.com');
    await user.type(screen.getByLabelText(/password/i, { selector: 'input' }), 'password');
    await user.click(screen.getByRole('button', { name: /sign in/i }));

    // /dashboard is not in this memory router, so the catch-all renders.
    expect(await screen.findByTestId('elsewhere')).toBeInTheDocument();
  });

  /*
   * The server answers a bad password with a domain code, not field errors.
   * It must surface as a form-level message, not vanish.
   */
  it('shows the server message for invalid credentials', async () => {
    server.use(
      http.post(apiUrl('/auth/login'), () =>
        HttpResponse.json(
          {
            error: {
              code: 'invalid_credentials',
              message: 'These credentials do not match our records.',
              details: [],
              request_id: 'TEST',
            },
          },
          { status: 422 },
        ),
      ),
    );

    const user = userEvent.setup();
    renderLogin();

    await user.type(screen.getByLabelText(/email/i), 'ada@example.com');
    await user.type(screen.getByLabelText(/password/i, { selector: 'input' }), 'wrong-password');
    await user.click(screen.getByRole('button', { name: /sign in/i }));

    expect(await screen.findByRole('alert')).toHaveTextContent(/do not match our records/i);
  });

  it('maps server field errors back onto the inputs', async () => {
    server.use(
      http.post(apiUrl('/auth/login'), () =>
        HttpResponse.json(
          {
            error: {
              code: 'validation_failed',
              message: 'The given data was invalid.',
              details: [{ field: 'email', code: 'invalid', message: 'That address is blocked.' }],
              request_id: 'TEST',
            },
          },
          { status: 422 },
        ),
      ),
    );

    const user = userEvent.setup();
    renderLogin();

    await user.type(screen.getByLabelText(/email/i), 'blocked@example.com');
    await user.type(screen.getByLabelText(/password/i, { selector: 'input' }), 'password');
    await user.click(screen.getByRole('button', { name: /sign in/i }));

    expect(await screen.findByText(/that address is blocked/i)).toBeInTheDocument();
  });

  it('reports a suspended account distinctly', async () => {
    server.use(
      http.post(apiUrl('/auth/login'), () =>
        HttpResponse.json(
          {
            error: {
              code: 'account_suspended',
              message: 'This account has been suspended.',
              details: [],
              request_id: 'TEST',
            },
          },
          { status: 403 },
        ),
      ),
    );

    const user = userEvent.setup();
    renderLogin();

    await user.type(screen.getByLabelText(/email/i), 'banned@example.com');
    await user.type(screen.getByLabelText(/password/i, { selector: 'input' }), 'password');
    await user.click(screen.getByRole('button', { name: /sign in/i }));

    expect(await screen.findByRole('alert')).toHaveTextContent(/suspended/i);
  });

  it('does not leave the button spinning after a failure', async () => {
    server.use(
      http.post(apiUrl('/auth/login'), () =>
        HttpResponse.json(
          { error: { code: 'server_error', message: 'Boom.', details: [], request_id: 'TEST' } },
          { status: 500 },
        ),
      ),
    );

    const user = userEvent.setup();
    renderLogin();

    await user.type(screen.getByLabelText(/email/i), 'ada@example.com');
    await user.type(screen.getByLabelText(/password/i, { selector: 'input' }), 'password');
    await user.click(screen.getByRole('button', { name: /sign in/i }));

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /sign in/i })).toBeEnabled();
  });
});
