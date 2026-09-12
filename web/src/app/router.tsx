import {
  createBrowserRouter,
  type PatchRoutesOnNavigationFunction,
  type RouteObject,
} from 'react-router';

import { HomeRoute } from '@/features/home/routes/HomeRoute';

import { RequireAuth } from './guards/RequireAuth';
import { RequireGuest } from './guards/RequireGuest';
import { AppLayout } from './layouts/AppLayout';
import { AuthLayout } from './layouts/AuthLayout';
import { PublicLayout } from './layouts/PublicLayout';
import { RouteErrorBoundary } from './RouteErrorBoundary';

/** The signed-in shell the discovered areas are patched into. */
const SHELL_ROUTE_ID = 'shell';

/**
 * Route tree. Guards are navigation aids — they keep a user off a page that
 * would only 403 anyway. Authorization itself is entirely server-side; see
 * docs/ROLES_PERMISSIONS.md §5.
 *
 * The studio, admin and platform trees are NOT here: they are discovered on
 * first navigation (`discoverRoutes`, below), so first paint carries only what
 * every signed-in learner can reach.
 */
export const routes: RouteObject[] = [
  {
    path: '/',
    element: <PublicLayout />,
    errorElement: <RouteErrorBoundary />,
    children: [
      { index: true, element: <HomeRoute /> },
      /*
       * The catalogue is MEMBERS-ONLY, and that is structural rather than a
       * product choice: tenancy is resolved from the authenticated user, so an
       * anonymous request belongs to no academy and the API answers 401. The
       * guard turns that into a login redirect instead of an error screen.
       */
      {
        element: <RequireAuth />,
        children: [
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
            path: 'bundles/:slug',
            lazy: async () => ({
              Component: (await import('@/features/bundle/routes/BundleDetailRoute'))
                .BundleDetailRoute,
            }),
          },
          {
            path: 'downloads',
            lazy: async () => ({
              Component: (await import('@/features/download/routes/DownloadsRoute')).DownloadsRoute,
            }),
          },
          {
            path: 'downloads/:slug',
            lazy: async () => ({
              Component: (await import('@/features/download/routes/DownloadDetailRoute'))
                .DownloadDetailRoute,
            }),
          },
          // Its own path rather than `downloads/mine`: the library is a
          // different page from the shelf, not a filter on it.
          {
            path: 'my-downloads',
            lazy: async () => ({
              Component: (await import('@/features/download/routes/MyDownloadsRoute')).MyDownloadsRoute,
            }),
          },
        ],
      },
      /*
       * The certificate verification page — the ONLY genuinely public route
       * in the SPA. Its reader is a stranger holding a printed certificate
       * who has no account and never will, so it sits outside RequireAuth.
       * The academy is in the path because there is no user to resolve one
       * from, and the token is the credential (§ Multi-tenancy).
       */
      {
        path: 'verify/:tenant/:token',
        lazy: async () => ({
          Component: (await import('@/features/certification/routes/VerifyRoute')).VerifyRoute,
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
    // The player is full-bleed by design: it is not the dashboard shell
    // (docs/FRONTEND_ARCHITECTURE.md §1).
    //
    // It used to admit anonymous visitors for free previews. It cannot now —
    // see the catalogue note above. Preview items still work, but they mean
    // "try before you ENROL" rather than "try before you sign up".
    path: 'learn/:courseId',
    errorElement: <RouteErrorBoundary />,
    element: <RequireAuth />,
    children: [
      {
        index: true,
        lazy: async () => ({
          Component: (await import('@/features/learning/routes/PlayerRoute')).PlayerRoute,
        }),
      },
      /*
       * Two STATIC segments declared before `:itemId`, which would otherwise
       * swallow them. They are also the `action_path` values the server
       * freezes into a notification payload, so they are part of that
       * contract rather than a convenience: a link in a year-old email has to
       * still land somewhere.
       */
      {
        path: 'announcements',
        lazy: async () => ({
          Component: (await import('@/features/engagement/routes/CourseAnnouncementsRoute'))
            .CourseAnnouncementsRoute,
        }),
      },
      {
        path: 'discussions/:discussionId',
        lazy: async () => ({
          Component: (await import('@/features/engagement/routes/DiscussionRoute')).DiscussionRoute,
        }),
      },
      {
        path: ':itemId',
        lazy: async () => ({
          Component: (await import('@/features/learning/routes/PlayerRoute')).PlayerRoute,
        }),
      },
      // The quiz gets its own routes rather than a pane inside the player: an
      // attempt has a countdown and unsaved answers, and the player's Next
      // button would silently discard both.
      {
        children: [
          {
            path: ':itemId/quiz',
            lazy: async () => ({
              Component: (await import('@/features/quiz/routes/QuizIntroRoute')).QuizIntroRoute,
            }),
          },
          {
            path: ':itemId/quiz/:attemptId',
            lazy: async () => ({
              Component: (await import('@/features/quiz/routes/QuizRunnerRoute')).QuizRunnerRoute,
            }),
          },
          {
            path: ':itemId/quiz/:attemptId/result',
            lazy: async () => ({
              Component: (await import('@/features/quiz/routes/QuizResultRoute')).QuizResultRoute,
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
        id: SHELL_ROUTE_ID,
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

          /*
           * Buying. Inside the signed-in shell rather than the public layout:
           * there is no anonymous surface, and a basket belongs to a learner.
           */
          {
            path: 'cart',
            lazy: async () => ({
              Component: (await import('@/features/commerce/routes/CartRoute')).CartRoute,
            }),
          },
          {
            path: 'orders',
            lazy: async () => ({
              Component: (await import('@/features/commerce/routes/OrdersRoute')).OrdersRoute,
            }),
          },
          {
            path: 'orders/:id',
            lazy: async () => ({
              Component: (await import('@/features/commerce/routes/OrderDetailRoute'))
                .OrderDetailRoute,
            }),
          },

          {
            path: 'certificates',
            lazy: async () => ({
              Component: (await import('@/features/certification/routes/CertificatesRoute'))
                .CertificatesRoute,
            }),
          },

          {
            path: 'wishlist',
            lazy: async () => ({
              Component: (await import('@/features/engagement/routes/WishlistRoute')).WishlistRoute,
            }),
          },

          {
            path: 'calendar',
            lazy: async () => ({
              Component: (await import('@/features/live/routes/CalendarRoute')).CalendarRoute,
            }),
          },
          {
            path: 'webinars',
            lazy: async () => ({
              Component: (await import('@/features/live/routes/WebinarsRoute')).WebinarsRoute,
            }),
          },

          {
            path: 'achievements',
            lazy: async () => ({
              Component: (await import('@/features/gamification/routes/AchievementsRoute'))
                .AchievementsRoute,
            }),
          },
          {
            path: 'leaderboard',
            lazy: async () => ({
              Component: (await import('@/features/gamification/routes/LeaderboardRoute'))
                .LeaderboardRoute,
            }),
          },

          {
            path: 'notifications',
            lazy: async () => ({
              Component: (await import('@/features/notification/routes/NotificationsRoute'))
                .NotificationsRoute,
            }),
          },
          {
            path: 'account/notifications',
            lazy: async () => ({
              Component: (
                await import('@/features/notification/routes/NotificationPreferencesRoute')
              ).NotificationPreferencesRoute,
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
        ],
      },
    ],
  },

  { path: '*', element: <RouteErrorBoundary /> },
];

/**
 * Areas discovered the first time one of their paths is visited.
 *
 * The route table was the largest module on first paint — 15.4 KB of a
 * 45.5 KB minified entry chunk, nearly half of it these three trees — and
 * most sessions are learners who never open them. Until an area is patched
 * in, a path under it matches only the root splat, and React Router treats a
 * match with params as possibly partial: it asks here before rendering, on a
 * first load and on a client navigation alike. Patching the same children
 * twice is a no-op (React Router's `isSameRoute`), so a second visit costs
 * nothing.
 *
 * A new area is one line here and a file in ./routes. A new page inside one
 * goes in that file — never back in the eager table, which router.test.tsx
 * checks.
 */
const AREAS: Record<string, () => Promise<RouteObject[]>> = {
  studio: async () => (await import('./routes/studio')).studioRoutes,
  admin: async () => (await import('./routes/admin')).adminRoutes,
  platform: async () => (await import('./routes/platform')).platformRoutes,
};

/**
 * The academy's public site, patched at the ROOT instead of into the shell.
 *
 * Every area above is a page for somebody signed in, so it belongs under the
 * shell's layout and its guards. `/a/:academy` is the opposite: no account, no
 * shell, the academy's own name in the header. Patching it into the shell
 * would wrap a stranger's page in a layout that assumes a user.
 */
const PUBLIC_AREA = 'a';

export const discoverRoutes: PatchRoutesOnNavigationFunction = async ({ path, patch }) => {
  const segment = path.split('/')[1] ?? '';

  if (segment === PUBLIC_AREA) {
    patch(null, (await import('./routes/public')).publicSiteRoutes);

    return;
  }

  const load = AREAS[segment];

  if (load) {
    patch(SHELL_ROUTE_ID, await load());
  }
};

export const router = createBrowserRouter(routes, { patchRoutesOnNavigation: discoverRoutes });
