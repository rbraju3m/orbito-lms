import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it, vi } from 'vitest';

import { apiUrl, sessionFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { ProfileRoute } from './ProfileRoute';

/*
 * The language a member picks for themselves (docs/I18N.md). The picker offers
 * exactly what the session says the academy speaks, plus handing the choice
 * back to the academy — which is `null`, not English.
 */
function serve(locale: string | null) {
  const patched = vi.fn();
  const user = { ...sessionFixture().user, locale };

  server.use(
    http.get(apiUrl('/auth/me'), () => HttpResponse.json({ data: sessionFixture({ user }) })),
    http.get(apiUrl('/account/profile'), () => HttpResponse.json({ data: user })),
    http.patch(apiUrl('/account/profile'), async ({ request }) => {
      const body = (await request.json()) as Record<string, unknown>;
      patched(body);
      return HttpResponse.json({ data: { ...user, ...body } });
    }),
  );

  return patched;
}

async function chooseLanguage(label: string) {
  await userEvent.click((await screen.findAllByLabelText('Language'))[0]!);
  await userEvent.click(await screen.findByRole('option', { name: label }));
  await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));
}

describe('ProfileRoute — language', () => {
  it('saves Bengali', async () => {
    const patched = serve(null);
    renderWithRouter(<ProfileRoute />);

    await chooseLanguage('বাংলা');

    await waitFor(() =>
      expect(patched).toHaveBeenCalledWith(expect.objectContaining({ locale: 'bn' })),
    );
  });

  it('hands the choice back to the academy as null', async () => {
    const patched = serve('bn');
    renderWithRouter(<ProfileRoute />);

    await chooseLanguage("The academy's language");

    await waitFor(() =>
      expect(patched).toHaveBeenCalledWith(expect.objectContaining({ locale: null })),
    );
  });

  it('offers only what the academy speaks', async () => {
    serve(null);
    renderWithRouter(<ProfileRoute />);

    await userEvent.click((await screen.findAllByLabelText('Language'))[0]!);

    const options = (await screen.findAllByRole('option')).map((option) => option.textContent);
    expect(options).toEqual(["The academy's language", 'English', 'বাংলা']);
  });
});
