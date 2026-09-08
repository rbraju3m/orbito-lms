import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { NotificationPreferences } from '../api/types';
import { NotificationPreferencesRoute } from './NotificationPreferencesRoute';

const API = 'http://localhost:8000/api/v1';

function matrix(mailEnabled = true): NotificationPreferences {
  return {
    groups: [
      {
        key: 'learning',
        label: 'Learning',
        types: [
          {
            key: 'announcement.published',
            label: 'Course announcements',
            description: 'When staff post an announcement in a course you are enrolled in.',
            group: 'learning',
            channels: [
              { channel: 'database', label: 'In-app', enabled: true, locked: true },
              { channel: 'mail', label: 'Email', enabled: mailEnabled, locked: false },
            ],
          },
        ],
      },
      { key: 'teaching', label: 'Teaching', types: [] },
    ],
  };
}

function serve() {
  server.use(
    http.get(`${API}/notification-preferences`, () => HttpResponse.json({ data: matrix() })),
  );
}

describe('NotificationPreferencesRoute', () => {
  it('renders the locked in-app switch rather than hiding it', async () => {
    /*
     * A missing switch reads as a bug; a disabled one that explains itself
     * does not. Silencing the inbox would destroy the record, not the
     * interruption — which is why only email is switchable.
     */
    serve();
    renderWithRouter(<NotificationPreferencesRoute />);

    const inApp = await screen.findByLabelText('Course announcements — In-app');
    expect(inApp).toBeDisabled();
    expect(inApp).toBeChecked();

    expect(screen.getByLabelText('Course announcements — Email')).toBeEnabled();
  });

  it('sends only the switch that moved', async () => {
    /*
     * Posting the whole matrix back would make every save a race between two
     * open tabs, and the older one's copy would win.
     */
    serve();
    let body: unknown;
    server.use(
      http.put(`${API}/notification-preferences`, async ({ request }) => {
        body = await request.json();
        return HttpResponse.json({ data: matrix(false) });
      }),
    );

    renderWithRouter(<NotificationPreferencesRoute />);

    await userEvent.click(await screen.findByLabelText('Course announcements — Email'));

    expect(body).toEqual({
      preferences: [{ type: 'announcement.published', channel: 'mail', enabled: false }],
    });
  });

  it('does not render a group with nothing in it', async () => {
    serve();
    renderWithRouter(<NotificationPreferencesRoute />);

    expect(await screen.findByText('Learning')).toBeInTheDocument();
    // A learner should not read past an empty block of instructor switches.
    expect(screen.queryByText('Teaching')).not.toBeInTheDocument();
  });
});
