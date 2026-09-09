import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it, vi } from 'vitest';

import { apiUrl, sessionFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { Academy } from '../api/academy';
import { AcademySettingsRoute } from './AcademySettingsRoute';

function academy(overrides: Partial<Academy> = {}): Academy {
  return {
    slug: 'north-college',
    name: 'North College',
    support_email: null,
    registration_mode: 'open',
    registration_mode_label: 'Anyone with the link',
    signup_path: '/register?academy=north-college',
    registration_modes: [
      { value: 'open', label: 'Anyone with the link', available: true },
      { value: 'invite', label: 'Invitation only', available: false },
      { value: 'closed', label: 'Nobody — admins create accounts', available: true },
    ],
    ...overrides,
  };
}

function serve(row: Academy) {
  server.use(
    http.get(apiUrl('/auth/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['settings.view', 'settings.update'] }) }),
    ),
    http.get(apiUrl('/admin/academy'), () => HttpResponse.json({ data: row })),
  );
}

describe('AcademySettingsRoute', () => {
  it('shows the signup link an academy hands out', async () => {
    serve(academy());
    renderWithRouter(<AcademySettingsRoute />);

    const link = (await screen.findByLabelText('Signup link')) as HTMLInputElement;

    // Absolute only at the moment of sharing — the server stores it relative.
    expect(link.value).toContain('/register?academy=north-college');
  });

  /*
   * Invitations are declared server-side and not built. An option that
   * silently closed registration instead would be worse than no option.
   */
  it('offers the invitation mode as unavailable rather than hiding it', async () => {
    serve(academy());
    renderWithRouter(<AcademySettingsRoute />);

    const invite = (await screen.findByLabelText('Invitation only')) as HTMLInputElement;

    expect(invite).toBeDisabled();
    expect(screen.getByText(/invitations are not built/i)).toBeInTheDocument();
  });

  it('closes sign-ups', async () => {
    const patched = vi.fn();

    serve(academy());
    server.use(
      http.patch(apiUrl('/admin/academy'), async ({ request }) => {
        patched(await request.json());
        return HttpResponse.json({ data: academy({ registration_mode: 'closed' }) });
      }),
    );

    renderWithRouter(<AcademySettingsRoute />);

    await userEvent.click(await screen.findByLabelText(/Nobody/));
    await userEvent.click(screen.getByRole('button', { name: 'Save' }));

    expect(patched).toHaveBeenCalledWith({ registration_mode: 'closed' });
  });

  /*
   * The link keeps working as a URL when sign-ups are closed, so the screen
   * has to say it will refuse anyone who follows it.
   */
  it('warns that the link refuses people while sign-ups are closed', async () => {
    serve(
      academy({ registration_mode: 'closed', registration_mode_label: 'Nobody — admins create accounts' }),
    );
    renderWithRouter(<AcademySettingsRoute />);

    expect(await screen.findByText(/will\s+currently refuse anyone/i)).toBeInTheDocument();
  });

  it('cannot save until something changes', async () => {
    serve(academy());
    renderWithRouter(<AcademySettingsRoute />);

    expect(await screen.findByRole('button', { name: 'Save' })).toBeDisabled();
  });
});
