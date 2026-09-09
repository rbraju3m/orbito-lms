import { screen, waitFor } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, sessionFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { RequirePlatformOperator } from './RequirePlatformOperator';

function serveSession(overrides: Record<string, unknown>) {
  server.use(
    http.get(apiUrl('/auth/me'), () => HttpResponse.json({ data: sessionFixture(overrides) })),
  );
}

/*
 * The registry is gated by the central operator FLAG, never by a permission.
 * Permissions are roles and roles live inside an academy's schema, so the most
 * powerful account in an academy still has no business here — which is the one
 * case worth a test.
 */
describe('RequirePlatformOperator', () => {
  it('hides the registry from an academy super admin', async () => {
    serveSession({
      roles: ['super_admin'],
      permissions: ['user.view', 'settings.view', 'course.create'],
      is_platform_operator: false,
    });

    renderWithRouter(<RequirePlatformOperator />, { path: '/' });

    await waitFor(() =>
      expect(screen.getByText(/platform operator/i)).toBeInTheDocument(),
    );
  });

  it('renders the registry for a platform operator', async () => {
    serveSession({ roles: [], permissions: [], is_platform_operator: true });

    const { container } = renderWithRouter(<RequirePlatformOperator />, { path: '/' });

    await waitFor(() => expect(container.querySelector('[role="alert"]')).toBeNull());
    expect(screen.queryByText(/platform operator's area/i)).not.toBeInTheDocument();
  });
});
