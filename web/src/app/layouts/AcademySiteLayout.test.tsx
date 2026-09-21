import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';
import { setTestViewportWidth } from '@/shared/test/setup';

import { AcademySiteLayout } from './AcademySiteLayout';

const ACADEMY = '/public/dhaka-art-school';
const PATH = '/a/:academy';
const ROUTE = '/a/dhaka-art-school';

function serve({ registrationOpen = true } = {}) {
  server.use(
    http.get(apiUrl(ACADEMY), () =>
      HttpResponse.json({
        data: {
          slug: 'dhaka-art-school',
          name: 'Dhaka Art School',
          logo_url: null,
          support_email: null,
          registration_open: registrationOpen,
        },
      }),
    ),
    http.get(apiUrl(`${ACADEMY}/navigation`), () =>
      HttpResponse.json({ data: [{ slug: 'about', title: 'About the school' }] }),
    ),
  );
}

describe('AcademySiteLayout', () => {
  it('offers the same links in the header row and in the narrow-screen menu', async () => {
    serve();
    const user = userEvent.setup();

    renderWithRouter(<AcademySiteLayout />, { path: PATH, route: ROUTE });

    expect(await screen.findByText('Dhaka Art School')).toBeInTheDocument();
    // jsdom applies no media queries, so both the row and the menu are in the tree.
    const pageLinks = await screen.findAllByRole('link', { name: 'About the school' });
    expect(pageLinks).toHaveLength(2);
    for (const link of pageLinks) {
      expect(link).toHaveAttribute('href', '/a/dhaka-art-school/p/about');
    }

    const burger = screen.getByRole('button', { name: 'Menu' });
    expect(burger).toHaveAttribute('aria-expanded', 'false');
    await user.click(burger);
    expect(burger).toHaveAttribute('aria-expanded', 'true');

    const menu = screen.getByRole('navigation');
    expect(within(menu).getByRole('link', { name: 'Blog' })).toHaveAttribute(
      'href',
      '/a/dhaka-art-school/blog',
    );
    expect(within(menu).getByRole('link', { name: 'Sign in' })).toHaveAttribute(
      'href',
      '/login?academy=dhaka-art-school',
    );
    expect(await within(menu).findByRole('link', { name: 'Sign up' })).toHaveAttribute(
      'href',
      '/register?academy=dhaka-art-school',
    );
  });

  // The testing library does not honour `inert`, so these assert the
  // attribute: it is what keeps a browser's Tab key out of the menu.
  it('keeps the collapsed menu out of the tab order at desktop width', async () => {
    serve();

    renderWithRouter(<AcademySiteLayout />, { path: PATH, route: ROUTE });

    await screen.findByRole('link', { name: 'Dhaka Art School' });
    expect(document.querySelector('nav')).toHaveAttribute('inert');
  });

  it('lets a keyboard into the menu only while it is open on a narrow screen', async () => {
    setTestViewportWidth(360);
    serve();
    const user = userEvent.setup();

    renderWithRouter(<AcademySiteLayout />, { path: PATH, route: ROUTE });

    await screen.findByRole('link', { name: 'Dhaka Art School' });
    expect(document.querySelector('nav')).toHaveAttribute('inert');

    await user.click(screen.getByRole('button', { name: 'Menu' }));
    expect(document.querySelector('nav')).not.toHaveAttribute('inert');
  });

  it('names the home link even when there is no academy name to show', async () => {
    server.use(
      http.get(apiUrl(ACADEMY), () =>
        HttpResponse.json(
          { error: { code: 'not_found', message: 'Not found.', details: [], request_id: 'r' } },
          { status: 404 },
        ),
      ),
      http.get(apiUrl(`${ACADEMY}/navigation`), () => HttpResponse.json({ data: [] })),
    );

    renderWithRouter(<AcademySiteLayout />, { path: PATH, route: ROUTE });

    expect(await screen.findByRole('link', { name: 'Home' })).toHaveAttribute(
      'href',
      '/a/dhaka-art-school',
    );
  });

  it('leaves Sign up out of both places when the academy is not taking signups', async () => {
    serve({ registrationOpen: false });

    renderWithRouter(<AcademySiteLayout />, { path: PATH, route: ROUTE });

    expect(await screen.findByText('Dhaka Art School')).toBeInTheDocument();
    expect(screen.getAllByRole('link', { name: 'Sign in' })).toHaveLength(2);
    expect(screen.queryByRole('link', { name: 'Sign up' })).not.toBeInTheDocument();
  });
});
