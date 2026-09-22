import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, paginated } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { Invitation } from '../api/invitations';
import { InvitationsRoute } from './InvitationsRoute';

function invitation(overrides: Partial<Invitation> = {}): Invitation {
  return {
    id: 'i-1',
    email: 'ada@example.test',
    role: 'student',
    role_label: 'Student',
    status: 'pending',
    status_label: 'Pending',
    sent_count: 1,
    last_sent_at: '2026-09-20T00:00:00Z',
    expires_at: '2026-10-04T00:00:00Z',
    accepted_at: null,
    revoked_at: null,
    created_at: '2026-09-20T00:00:00Z',
    can_resend: true,
    can_revoke: true,
    ...overrides,
  };
}

function serve(rows: Invitation[]) {
  server.use(http.get(apiUrl('/admin/invitations'), () => HttpResponse.json(paginated(rows))));
}

describe('InvitationsRoute', () => {
  it('lists who was invited, as what, and what became of it', async () => {
    serve([
      invitation(),
      invitation({
        id: 'i-2',
        email: 'grace@example.test',
        role: 'instructor',
        role_label: 'Instructor',
        status: 'accepted',
        status_label: 'Accepted',
        accepted_at: '2026-09-21T00:00:00Z',
        can_resend: false,
        can_revoke: false,
      }),
    ]);
    renderWithRouter(<InvitationsRoute />);

    expect(await screen.findByText('ada@example.test')).toBeInTheDocument();
    expect(screen.getByText('Instructor')).toBeInTheDocument();
    // A settled invitation offers nothing to do, rather than a button that 409s.
    expect(screen.getAllByRole('button', { name: /^Revoke the invitation/ })).toHaveLength(1);
    expect(screen.getByText(/accepted/i, { selector: 'p' })).toBeInTheDocument();
  });

  it('shows an empty state that says what an invitation does', async () => {
    serve([]);
    renderWithRouter(<InvitationsRoute />);

    expect(await screen.findByText('No invitations yet')).toBeInTheDocument();
  });

  it('invites somebody as an instructor', async () => {
    serve([]);
    const bodies: unknown[] = [];
    server.use(
      http.post(apiUrl('/admin/invitations'), async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ data: invitation() }, { status: 201 });
      }),
    );
    const user = userEvent.setup();
    renderWithRouter(<InvitationsRoute />);

    await user.click(await screen.findByRole('button', { name: 'Invite' }));
    const dialog = within(await screen.findByRole('dialog'));
    await user.type(dialog.getByLabelText(/Email/), 'grace@example.test');
    await user.click(dialog.getByText('Instructor'));
    await user.click(dialog.getByRole('button', { name: 'Send invitation' }));

    await waitFor(() =>
      expect(bodies).toEqual([{ email: 'grace@example.test', role: 'instructor' }]),
    );
    // Sent, so the dialog closes.
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
  });

  it('puts the server’s refusal on the email field', async () => {
    serve([]);
    server.use(
      http.post(apiUrl('/admin/invitations'), () =>
        HttpResponse.json(
          {
            error: {
              code: 'validation_failed',
              message: 'The given data was invalid.',
              details: [
                {
                  field: 'email',
                  code: 'unique',
                  message: 'This address already has an account, so it cannot be invited.',
                },
              ],
              request_id: 'TEST',
            },
          },
          { status: 422 },
        ),
      ),
    );
    const user = userEvent.setup();
    renderWithRouter(<InvitationsRoute />);

    await user.click(await screen.findByRole('button', { name: 'Invite' }));
    const dialog = within(await screen.findByRole('dialog'));
    await user.type(dialog.getByLabelText(/Email/), 'taken@example.test');
    await user.click(dialog.getByRole('button', { name: 'Send invitation' }));

    expect(await dialog.findByText(/already has an account/)).toBeInTheDocument();
  });

  it('revokes after asking', async () => {
    serve([invitation()]);
    let revoked = false;
    server.use(
      http.post(apiUrl('/admin/invitations/i-1/revoke'), () => {
        revoked = true;
        return HttpResponse.json({ data: invitation({ status: 'revoked' }) });
      }),
    );
    const user = userEvent.setup();
    renderWithRouter(<InvitationsRoute />);

    await user.click(
      await screen.findByRole('button', { name: 'Revoke the invitation to ada@example.test' }),
    );
    const dialog = within(await screen.findByRole('dialog'));
    await user.click(dialog.getByRole('button', { name: 'Revoke' }));

    await waitFor(() => expect(revoked).toBe(true));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
  });
});
