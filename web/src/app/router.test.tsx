import { createMemoryRouter, type RouteObject } from 'react-router';
import { describe, expect, it, vi } from 'vitest';

import { discoverRoutes, routes } from './router';

/** Every path the eager table declares, however deeply nested. */
function eagerPaths(table: RouteObject[]): string[] {
  return table.flatMap((route) => [
    ...(route.path ? [route.path] : []),
    ...eagerPaths(route.children ?? []),
  ]);
}

/** Where a first visit to `path` lands, once discovery has had its turn. */
async function land(path: string): Promise<string[]> {
  const router = createMemoryRouter(routes, {
    initialEntries: [path],
    patchRoutesOnNavigation: discoverRoutes,
  });

  await vi.waitFor(() => expect(router.state.initialized).toBe(true));

  return router.state.matches.flatMap((match) => (match.route.path ? [match.route.path] : []));
}

describe('route discovery', () => {
  /*
   * The point of the split: the studio, admin and platform tables are not on
   * first paint. One added back to the eager table would quietly undo it.
   */
  it('keeps the studio, admin and platform tables out of the eager route table', () => {
    const areas = eagerPaths(routes).filter((path) => /^(studio|admin|platform)(\/|$)/.test(path));

    expect(areas).toEqual([]);
  });

  it.each([
    ['/admin/refund-reports', 'admin/refund-reports'],
    ['/admin/webhooks/42', 'admin/webhooks/:endpointId'],
    ['/studio', 'studio'],
    ['/studio/courses/7/grading', 'studio/courses/:id/grading'],
    ['/platform/academies/demo-academy', 'platform/academies/:slug'],
  ])('discovers %s on a first visit, deep links included', async (url, declared) => {
    expect((await land(url)).at(-1)).toBe(declared);
  });

  it('serves a learner page from the table it already has', async () => {
    expect((await land('/dashboard')).at(-1)).toBe('dashboard');
  });

  it('still ends at not-found for a path in no area', async () => {
    expect((await land('/nowhere-at-all')).at(-1)).toBe('*');
  });
});
