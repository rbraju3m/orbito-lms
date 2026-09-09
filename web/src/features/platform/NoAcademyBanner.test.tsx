import { screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { useSession } from '@/features/auth/hooks/useSession';
import { apiUrl, sessionFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { NoAcademyBanner } from './NoAcademyBanner';

function serveSession(overrides: Record<string, unknown>) {
  server.use(
    http.get(apiUrl('/auth/me'), () => HttpResponse.json({ data: sessionFixture(overrides) })),
  );
}

/**
 * Renders nothing until the session query answers.
 *
 * The banner renders null both while loading and when there is nothing to say,
 * so asserting its absence proves nothing on its own — this gives the negative
 * tests something to wait for.
 */
function SessionProbe() {
  const { isLoading } = useSession();

  return <div>{isLoading ? 'loading' : 'ready'}</div>;
}

/*
 * An operator with `tenant_id` null is on the CENTRAL connection, where none of
 * the domain tables exist — the API answers those routes with 409
 * `no_academy_selected`. Nothing on the screen would explain that, and an
 * operator who has just stepped out of an academy hits it immediately.
 */
describe('NoAcademyBanner', () => {
  it('tells an operator with no academy why the app is empty, and where to go', async () => {
    serveSession({ is_platform_operator: true, academy: null });

    renderWithRouter(<NoAcademyBanner />);

    expect(await screen.findByText(/not inside an academy/i)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Choose an academy' })).toHaveAttribute(
      'href',
      '/platform/academies',
    );
  });

  it('stays out of the way once the operator is inside one', async () => {
    serveSession({
      is_platform_operator: true,
      academy: { id: 'a1', slug: 'demo-academy', name: 'Demo Academy' },
    });

    renderWithRouter(
      <>
        <NoAcademyBanner />
        <SessionProbe />
      </>,
    );

    // Wait for the session to actually resolve first, or this would pass
    // merely because the query had not answered yet.
    expect(await screen.findByText('ready')).toBeInTheDocument();
    expect(screen.queryByText(/not inside an academy/i)).not.toBeInTheDocument();
  });

  /*
   * A member always belongs to an academy, so for them this is unreachable
   * rather than merely hidden — and showing it would be nonsense.
   */
  it('is never shown to a member', async () => {
    serveSession({ is_platform_operator: false, academy: null });

    renderWithRouter(
      <>
        <NoAcademyBanner />
        <SessionProbe />
      </>,
    );

    expect(await screen.findByText('ready')).toBeInTheDocument();
    expect(screen.queryByText(/not inside an academy/i)).not.toBeInTheDocument();
  });
});
