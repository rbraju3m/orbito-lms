import type { RouteObject } from 'react-router';

import { AcademySiteLayout } from '../layouts/AcademySiteLayout';
import { RouteErrorBoundary } from '../RouteErrorBoundary';

/**
 * ONE academy's public site, at `/a/:academy`. Discovered the first time such
 * a path is visited (router.tsx), like the studio and admin tables.
 *
 * Patched at the ROOT rather than into the signed-in shell, which every other
 * discovered area goes into: these pages have no user, wear the academy's
 * name rather than Orbito's, and must not sit under a layout that assumes an
 * account. That is the whole reason this table is separate.
 *
 * The `/a/` prefix is deliberately short and deliberately not `/academy/`:
 * it is in a link an academy prints, and it can never collide with a page of
 * the signed-in app, which is what a bare `/:academy` would eventually do.
 */
export const publicSiteRoutes: RouteObject[] = [
  {
    path: 'a/:academy',
    element: <AcademySiteLayout />,
    errorElement: <RouteErrorBoundary />,
    children: [
      {
        index: true,
        lazy: async () => ({
          Component: (await import('@/features/publicsite/routes/AcademyHomeRoute'))
            .AcademyHomeRoute,
        }),
      },
      {
        path: 'courses/:slug',
        lazy: async () => ({
          Component: (await import('@/features/publicsite/routes/PublicCourseRoute'))
            .PublicCourseRoute,
        }),
      },
      {
        path: 'webinars/:slug',
        lazy: async () => ({
          Component: (await import('@/features/publicsite/routes/PublicWebinarRoute'))
            .PublicWebinarRoute,
        }),
      },
    ],
  },
];
