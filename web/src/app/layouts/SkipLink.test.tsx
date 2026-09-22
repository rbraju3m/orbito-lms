import { fireEvent, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import type { ReactElement } from 'react';
import { describe, expect, it } from 'vitest';

import { PlayerRoute } from '@/features/learning/routes/PlayerRoute';
import { apiUrl, itemFixture, playerFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { AcademySiteLayout } from './AcademySiteLayout';
import { AppLayout } from './AppLayout';
import { AuthLayout } from './AuthLayout';
import { PublicLayout } from './PublicLayout';

/*
 * Every shell, in one table: a skip link on four of five teaches a keyboard
 * user not to trust it (docs/DESIGN_SYSTEM.md §5). A new shell belongs here.
 */
const SHELLS: { name: string; ui: ReactElement; path: string; route: string }[] = [
  { name: 'AppLayout', ui: <AppLayout />, path: '/dashboard', route: '/dashboard' },
  { name: 'PublicLayout', ui: <PublicLayout />, path: '/', route: '/' },
  { name: 'AuthLayout', ui: <AuthLayout />, path: '/login', route: '/login' },
  {
    name: 'AcademySiteLayout',
    ui: <AcademySiteLayout />,
    path: '/a/:academy',
    route: '/a/dhaka-art-school',
  },
  {
    name: 'the player',
    ui: <PlayerRoute />,
    path: '/learn/:courseId/:itemId',
    route: '/learn/course-uuid/item-1',
  },
];

function serve() {
  server.use(
    http.get(apiUrl('/public/dhaka-art-school'), () =>
      HttpResponse.json({
        data: {
          slug: 'dhaka-art-school',
          name: 'Dhaka Art School',
          logo_url: null,
          support_email: null,
          registration_open: true,
        },
      }),
    ),
    http.get(apiUrl('/public/dhaka-art-school/navigation'), () => HttpResponse.json({ data: [] })),
    http.get(apiUrl('/learn/courses/course-uuid'), () =>
      HttpResponse.json({ data: playerFixture() }),
    ),
    http.get(apiUrl('/learn/items/:id'), () => HttpResponse.json({ data: itemFixture() })),
    http.get(apiUrl('/learn/items/:id/notes'), () => HttpResponse.json({ data: [] })),
  );
}

describe.each(SHELLS)('$name', ({ ui, path, route }) => {
  it('is the first stop for Tab and lands focus on the main region', async () => {
    serve();
    const user = userEvent.setup();

    renderWithRouter(ui, { path, route });

    // The player renders its shell only once the course has loaded.
    const link = await screen.findByRole('link', { name: 'Skip to content' });

    await user.tab();
    expect(link).toHaveFocus();

    await user.keyboard('{Enter}');
    const main = screen.getByRole('main');
    expect(main).toHaveFocus();
    // The region takes focus without becoming a Tab stop of its own.
    expect(main).toHaveAttribute('tabindex', '-1');
    // One target: two <main>s would leave the link's destination ambiguous.
    expect(document.querySelectorAll('main')).toHaveLength(1);
  });

  it('focuses the region instead of following the fragment', async () => {
    serve();

    renderWithRouter(ui, { path, route });

    const link = await screen.findByRole('link', { name: 'Skip to content' });
    // `false` means the default was prevented: no `#main-content` in the
    // location, so no history entry for Back to step through.
    expect(fireEvent.click(link)).toBe(false);
    expect(screen.getByRole('main')).toHaveFocus();
  });
});
