import { screen, waitFor } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, sessionFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { RequirePermission } from './RequirePermission';

/*
 * These assert the UI does not offer areas the user cannot use. The server
 * enforces the same rules independently — see ROLES_PERMISSIONS.md §5.
 */
describe('RequirePermission', () => {
  it('hides the area from a caller who lacks the permission', async () => {
    server.use(http.get(apiUrl('/auth/me'), () => HttpResponse.json({ data: sessionFixture() })));

    renderWithRouter(<RequirePermission anyOf={['user.view']} />, { path: '/' });

    await waitFor(() =>
      expect(screen.getByText(/do not have access to this area/i)).toBeInTheDocument(),
    );
  });

  it('renders the area for a caller who holds one of the permissions', async () => {
    server.use(
      http.get(apiUrl('/auth/me'), () =>
        HttpResponse.json({ data: sessionFixture({ permissions: ['user.view'] }) }),
      ),
    );

    const { container } = renderWithRouter(
      <RequirePermission anyOf={['user.view', 'settings.view']} />,
      {
        path: '/',
      },
    );

    await waitFor(() =>
      expect(screen.queryByText(/do not have access to this area/i)).not.toBeInTheDocument(),
    );
    expect(container).toBeInTheDocument();
  });

  it('does not flash the denial while the session is still loading', () => {
    renderWithRouter(<RequirePermission anyOf={['user.view']} />, { path: '/' });

    expect(screen.queryByText(/do not have access to this area/i)).not.toBeInTheDocument();
  });
});
