import { screen, waitFor } from '@testing-library/react';
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
    logo_media_id: null,
    logo_url: null,
    registration_mode: 'open',
    registration_mode_label: 'Anyone with the link',
    signup_path: '/register?academy=north-college',
    registration_modes: [
      { value: 'open', label: 'Anyone with the link', available: true },
      { value: 'invite', label: 'Invitation only', available: true },
      { value: 'closed', label: 'Nobody — admins create accounts', available: true },
    ],
    ...overrides,
  };
}

function serve(row: Academy) {
  server.use(
    http.get(apiUrl('/auth/me'), () =>
      HttpResponse.json({
        data: sessionFixture({ permissions: ['settings.view', 'settings.update'] }),
      }),
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

  // Invitations work in every mode; the settings screen says so beside each.
  it('offers invitation only, and says invitations still work when closed', async () => {
    serve(academy());
    renderWithRouter(<AcademySettingsRoute />);

    const invite = (await screen.findByLabelText('Invitation only')) as HTMLInputElement;

    expect(invite).toBeEnabled();
    expect(screen.getByText('Nobody signs up. Invitations still work.')).toBeInTheDocument();
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
      academy({
        registration_mode: 'closed',
        registration_mode_label: 'Nobody — admins create accounts',
      }),
    );
    renderWithRouter(<AcademySettingsRoute />);

    expect(await screen.findByText(/will\s+currently refuse anyone/i)).toBeInTheDocument();
  });

  it('cannot save until something changes', async () => {
    serve(academy());
    renderWithRouter(<AcademySettingsRoute />);

    expect(await screen.findByRole('button', { name: 'Save' })).toBeDisabled();
  });

  it('uploads a logo into its own collection, then saves it at once', async () => {
    const uploaded = vi.fn();
    const patched = vi.fn();
    serve(academy());
    server.use(
      http.post(apiUrl('/media'), async ({ request }) => {
        uploaded((await request.formData()).get('collection'));
        return HttpResponse.json(
          { data: { id: 'logo-uuid', ref: 42, url: 'https://cdn.test/logo.png' } },
          { status: 201 },
        );
      }),
      http.patch(apiUrl('/admin/academy'), async ({ request }) => {
        patched(await request.json());
        return HttpResponse.json({
          data: academy({ logo_media_id: 42, logo_url: 'https://cdn.test/logo.png' }),
        });
      }),
    );
    const { container } = renderWithRouter(<AcademySettingsRoute />);

    await screen.findByText('Upload a logo');
    const input = container.querySelector<HTMLInputElement>('input[type="file"]');
    await userEvent.upload(input!, new File(['png'], 'logo.png', { type: 'image/png' }));

    await waitFor(() => expect(patched).toHaveBeenCalledWith({ logo_media_id: 42 }));
    expect(uploaded).toHaveBeenCalledWith('academy_logo');
    expect(await screen.findByRole('img', { name: 'North College logo' })).toHaveAttribute(
      'src',
      'https://cdn.test/logo.png',
    );
    expect(screen.getByText('Replace the logo')).toBeInTheDocument();
  });

  it('takes the logo down', async () => {
    const patched = vi.fn();
    serve(academy({ logo_media_id: 42, logo_url: 'https://cdn.test/logo.png' }));
    server.use(
      http.patch(apiUrl('/admin/academy'), async ({ request }) => {
        patched(await request.json());
        return HttpResponse.json({ data: academy() });
      }),
    );
    renderWithRouter(<AcademySettingsRoute />);

    await userEvent.click(await screen.findByRole('button', { name: 'Remove logo' }));

    await waitFor(() => expect(patched).toHaveBeenCalledWith({ logo_media_id: null }));
    await waitFor(() =>
      expect(screen.queryByRole('img', { name: 'North College logo' })).not.toBeInTheDocument(),
    );
  });

  it('says why an upload was refused', async () => {
    serve(academy());
    server.use(
      http.post(apiUrl('/media'), () =>
        HttpResponse.json(
          {
            error: {
              code: 'validation_failed',
              message: 'That file type is not accepted here.',
              details: [],
              request_id: 'X',
            },
          },
          { status: 422 },
        ),
      ),
    );
    const { container } = renderWithRouter(<AcademySettingsRoute />);

    await screen.findByText('Upload a logo');
    const input = container.querySelector<HTMLInputElement>('input[type="file"]');
    await userEvent.upload(input!, new File(['gif'], 'logo.png', { type: 'image/png' }));

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'That file type is not accepted here.',
    );
  });
});
