import { screen, waitFor } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl } from '@/shared/test/handlers';
import { renderWithProviders } from '@/shared/test/render';
import { server } from '@/shared/test/server';

import { SystemStatusRoute } from './SystemStatusRoute';

describe('SystemStatusRoute', () => {
  it('shows a loading state before the request resolves', () => {
    renderWithProviders(<SystemStatusRoute />);

    expect(screen.getByRole('status', { name: /loading/i })).toBeInTheDocument();
  });

  it('renders each dependency check on success', async () => {
    renderWithProviders(<SystemStatusRoute />);

    expect(await screen.findByText(/all systems operational/i)).toBeInTheDocument();

    for (const name of ['database', 'cache', 'queue']) {
      expect(screen.getByText(name)).toBeInTheDocument();
    }
    expect(screen.getAllByText('up')).toHaveLength(3);
  });

  it('surfaces a degraded dependency rather than claiming health', async () => {
    server.use(
      http.get(apiUrl('/health'), () =>
        HttpResponse.json(
          {
            data: {
              status: 'degraded',
              app: 'Orbito',
              environment: 'testing',
              version: '0.1.0-phase2',
              time: '2026-09-07T10:00:00Z',
              checks: {
                database: { ok: true },
                cache: { ok: false, error: 'ConnectionException' },
                queue: { ok: true },
              },
            },
          },
          { status: 503 },
        ),
      ),
    );

    renderWithProviders(<SystemStatusRoute />);

    expect(await screen.findByText(/degraded/i)).toBeInTheDocument();
    expect(screen.getByText('down')).toBeInTheDocument();
    expect(screen.getByText('ConnectionException')).toBeInTheDocument();
  });

  it('renders an error state with the request id when the API fails', async () => {
    server.use(
      http.get(apiUrl('/health'), () =>
        HttpResponse.json(
          {
            error: {
              code: 'server_error',
              message: 'Something went wrong on our end.',
              details: [],
              request_id: 'ORBITO-XYZ',
            },
          },
          { status: 500 },
        ),
      ),
    );

    renderWithProviders(<SystemStatusRoute />);

    await waitFor(() =>
      expect(screen.getByText(/something went wrong on our end/i)).toBeInTheDocument(),
    );
    expect(screen.getByText('ORBITO-XYZ')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument();
  });
});
