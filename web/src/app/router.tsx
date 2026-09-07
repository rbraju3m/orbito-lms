import { createBrowserRouter } from 'react-router';

import { HomeRoute } from '@/features/home/routes/HomeRoute';

import { RequireAuth } from './guards/RequireAuth';
import { RequireGuest } from './guards/RequireGuest';
import { RequirePermission } from './guards/RequirePermission';
import { AppLayout } from './layouts/AppLayout';
import { AuthLayout } from './layouts/AuthLayout';
import { PublicLayout } from './layouts/PublicLayout';
import { RouteErrorBoundary } from './RouteErrorBoundary';

/**
 * Route tree. Guards are navigation aids — they keep a user off a page that
 * would only 403 anyway. Authorization itself is entirely server-side; see
 * docs/ROLES_PERMISSIONS.md §5.
 */
export const router = createBrowserRouter([
  {
    path: '/',
    element: <PublicLayout />,
    errorElement: <RouteErrorBoundary />,
    children: [
      { index: true, element: <HomeRoute /> },
      {
        path: 'courses',
        lazy: async () => ({
          Component: (await import('@/features/catalog/routes/CatalogRoute')).CatalogRoute,
        }),
      },
      {
        path: 'courses/:slug',
        lazy: async () => ({
          Component: (await import('@/features/catalog/routes/CourseDetailRoute'))
            .CourseDetailRoute,
        }),
      },
      {
        path: 'system',
        lazy: async () => ({
          Component: (await import('@/features/system/routes/SystemStatusRoute')).SystemStatusRoute,
        }),
      },
    ],
  },

  {
    element: <AuthLayout />,
    errorElement: <RouteErrorBoundary />,
    children: [
      // Verification and reset links are opened by people who may or may not
      // be signed in, so they sit outside the guest guard.
      {
        path: 'verify-email',
        lazy: async () => ({
          Component: (await import('@/features/auth/routes/VerifyEmailRoute')).VerifyEmailRoute,
        }),
      },
      {
        path: 'reset-password',
        lazy: async () => ({
          Component: (await import('@/features/auth/routes/ResetPasswordRoute')).ResetPasswordRoute,
        }),
      },
      {
        element: <RequireGuest />,
        children: [
          {
            path: 'login',
            lazy: async () => ({
              Component: (await import('@/features/auth/routes/LoginRoute')).LoginRoute,
            }),
          },
          {
            path: 'register',
            lazy: async () => ({
              Component: (await import('@/features/auth/routes/RegisterRoute')).RegisterRoute,
            }),
          },
          {
            path: 'forgot-password',
            lazy: async () => ({
              Component: (await import('@/features/auth/routes/ForgotPasswordRoute'))
                .ForgotPasswordRoute,
            }),
          },
        ],
      },
    ],
  },

  {
    element: <RequireAuth />,
    errorElement: <RouteErrorBoundary />,
    children: [
      {
        element: <AppLayout />,
        children: [
          {
            path: 'dashboard',
            lazy: async () => ({
              Component: (await import('@/features/dashboard/routes/DashboardRoute'))
                .DashboardRoute,
            }),
          },
          {
            path: 'dashboard/courses',
            lazy: async () => ({
              Component: (await import('@/features/dashboard/routes/MyCoursesRoute'))
                .MyCoursesRoute,
            }),
          },

          {
            path: 'account/profile',
            lazy: async () => ({
              Component: (await import('@/features/account/routes/ProfileRoute')).ProfileRoute,
            }),
          },
          {
            path: 'account/security',
            lazy: async () => ({
              Component: (await import('@/features/account/routes/SecurityRoute')).SecurityRoute,
            }),
          },

          {
            element: <RequirePermission anyOf={['course.create', 'course.update.own']} />,
            children: [
              {
                path: 'studio',
                lazy: async () => ({
                  Component: (await import('@/features/studio/routes/StudioHomeRoute'))
                    .StudioHomeRoute,
                }),
              },
            ],
          },

          {
            element: (
              <RequirePermission anyOf={['user.view', 'settings.view', 'instructor.view']} />
            ),
            children: [
              {
                path: 'admin',
                lazy: async () => ({
                  Component: (await import('@/features/admin/routes/AdminHomeRoute'))
                    .AdminHomeRoute,
                }),
              },
              {
                path: 'admin/instructors',
                lazy: async () => ({
                  Component: (await import('@/features/admin/routes/InstructorsRoute'))
                    .InstructorsRoute,
                }),
              },
            ],
          },
        ],
      },
    ],
  },

  { path: '*', element: <RouteErrorBoundary /> },
]);
