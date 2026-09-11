import type { RouteObject } from 'react-router';

import { RequirePlatformOperator } from '../guards/RequirePlatformOperator';

/**
 * The academy REGISTRY, not an academy's admin area. Guarded by the central
 * operator flag rather than a permission — permissions are roles and roles
 * live inside a schema, so an academy Super Admin holds every one of them and
 * still has no business here.
 *
 * Discovered the first time a `/platform` path is visited (router.tsx): one
 * account in the whole system ever opens it.
 */
export const platformRoutes: RouteObject[] = [
  {
    element: <RequirePlatformOperator />,
    children: [
      {
        path: 'platform/academies',
        lazy: async () => ({
          Component: (await import('@/features/platform/routes/AcademiesRoute')).AcademiesRoute,
        }),
      },
      {
        path: 'platform/academies/:slug',
        lazy: async () => ({
          Component: (await import('@/features/platform/routes/AcademyDetailRoute'))
            .AcademyDetailRoute,
        }),
      },
    ],
  },
];
