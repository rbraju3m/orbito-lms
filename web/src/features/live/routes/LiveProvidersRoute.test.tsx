import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { LiveProviderAccount } from '../api/types';
import { LiveProvidersRoute } from './LiveProvidersRoute';

const manual: LiveProviderAccount = {
  provider: 'manual',
  label: 'Paste a link',
  needs_account: false,
  is_connected: false,
  is_active: false,
  fields: [],
  setup_url: null,
  upcoming_sessions: 0,
  updated_at: null,
};

const zoom: LiveProviderAccount = {
  provider: 'zoom',
  label: 'Zoom',
  needs_account: true,
  is_connected: false,
  is_active: false,
  fields: [
    { key: 'account_id', label: 'Account ID', help: 'From the app.', required: true, secret: false },
    {
      key: 'client_secret',
      label: 'Client secret',
      help: 'Zoom shows this once.',
      required: true,
      secret: true,
    },
  ],
  setup_url: 'https://marketplace.zoom.us/develop/create',
  upcoming_sessions: 0,
  updated_at: null,
};

const list = (...providers: LiveProviderAccount[]) =>
  server.use(
    http.get(apiUrl('/admin/live-providers'), () => HttpResponse.json({ data: providers })),
  );

describe('LiveProvidersRoute', () => {
  it('builds the form from the fields the server declares', async () => {
    // Not a copy kept in the SPA: adding a provider is a case in the enum and
    // a class beside it, with no screen to remember.
    list(manual, zoom);
    renderWithRouter(<LiveProvidersRoute />);

    await userEvent.click(await screen.findByRole('button', { name: 'Connect' }));

    expect(await screen.findByLabelText(/Account ID/)).toBeInTheDocument();
    expect(screen.getByLabelText(/Client secret/)).toBeInTheDocument();
  });

  it('sends only the boxes that were filled in, so a partial update keeps the rest', async () => {
    const sent: Record<string, unknown>[] = [];

    list(manual, { ...zoom, is_connected: true, is_active: true });
    server.use(
      http.put(apiUrl('/admin/live-providers/zoom'), async ({ request }) => {
        sent.push((await request.json()) as Record<string, unknown>);

        return HttpResponse.json({ data: { ...zoom, is_connected: true } });
      }),
    );

    renderWithRouter(<LiveProvidersRoute />);

    await userEvent.click(await screen.findByRole('button', { name: 'Update' }));
    await userEvent.type(await screen.findByLabelText(/Account ID/), 'acct-2');
    await userEvent.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(sent).toHaveLength(1));
    // The secret is untouched, so it is not in the body at all — the API
    // would never have read it back to prefill it anyway.
    expect(sent[0]?.credentials).toEqual({ account_id: 'acct-2' });
  });

  it('names the fields the server says are missing', async () => {
    list(manual, zoom);
    server.use(
      http.put(apiUrl('/admin/live-providers/zoom'), () =>
        HttpResponse.json(
          {
            error: {
              code: 'live_provider_credentials_incomplete',
              message: 'The zoom credentials are incomplete: client_secret.',
              meta: { missing: ['client_secret'] },
            },
          },
          { status: 422 },
        ),
      ),
    );

    renderWithRouter(<LiveProvidersRoute />);

    await userEvent.click(await screen.findByRole('button', { name: 'Connect' }));
    await userEvent.type(await screen.findByLabelText(/Account ID/), 'acct-1');
    await userEvent.click(screen.getByRole('button', { name: 'Save' }));

    // On the box, not only in a banner: "incomplete" with nothing pointed at
    // is a dead end on a four-box form.
    expect(await screen.findByText('Needed before this can be used')).toBeInTheDocument();
  });

  it('says what a disconnect would strand before it happens', async () => {
    list(manual, { ...zoom, is_connected: true, is_active: true, upcoming_sessions: 3 });
    renderWithRouter(<LiveProvidersRoute />);

    await userEvent.click(await screen.findByRole('button', { name: 'Disconnect' }));

    expect(
      await screen.findByText(/3 sessions already scheduled keep their links/),
    ).toBeInTheDocument();
  });

  it('offers nothing to connect for the provider that connects to nothing', async () => {
    // Manual is listed — hiding it would suggest live sessions need an
    // integration — but it has no Connect button.
    list(manual);
    renderWithRouter(<LiveProvidersRoute />);

    expect(await screen.findByText('Paste a link')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Connect' })).not.toBeInTheDocument();
  });
});
