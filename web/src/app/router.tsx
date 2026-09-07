import { createBrowserRouter } from 'react-router';

import { HomeRoute } from '@/features/home/routes/HomeRoute';
import { SystemStatusRoute } from '@/features/system/routes/SystemStatusRoute';

import { PublicLayout } from './layouts/PublicLayout';
import { RouteErrorBoundary } from './RouteErrorBoundary';

/**
 * Route tree. Each surface (public / learn / dashboard / studio / admin) gets
 * its own layout and its own lazy chunk as its phase lands — see
 * docs/FRONTEND_ARCHITECTURE.md §2 for the full planned map.
 */
export const router = createBrowserRouter([
  {
    path: '/',
    element: <PublicLayout />,
    errorElement: <RouteErrorBoundary />,
    children: [
      { index: true, element: <HomeRoute /> },
      { path: 'system', element: <SystemStatusRoute /> },
      { path: '*', element: <RouteErrorBoundary /> },
    ],
  },
]);
