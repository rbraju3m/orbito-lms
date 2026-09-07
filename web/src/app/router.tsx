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
        ],
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
              {
                path: 'studio/courses',
                lazy: async () => ({
                  Component: (await import('@/features/studio/routes/StudioCoursesRoute'))
                    .StudioCoursesRoute,
                }),
              },
              {
                path: 'studio/courses/new',
                lazy: async () => ({
                  Component: (await import('@/features/studio/routes/NewCourseRoute'))
                    .NewCourseRoute,
                }),
              },
              {
                path: 'studio/courses/:id',
                lazy: async () => ({
                  Component: (await import('@/features/studio/routes/CourseEditorRoute'))
                    .CourseEditorRoute,
                }),
              },
              // Grading sits under the course rather than under a kind: one
              // list of work, whether it came from a quiz or an assignment.
              {
                path: 'studio/courses/:id/grading',
                lazy: async () => ({
                  Component: (await import('@/features/grading/routes/GradingQueueRoute'))
                    .GradingQueueRoute,
                }),
              },
              {
                path: 'studio/grading/quiz/:attemptId',
                lazy: async () => ({
                  Component: (await import('@/features/grading/routes/GradeAttemptRoute'))
                    .GradeAttemptRoute,
                }),
              },
              {
                path: 'studio/grading/assignment/:submissionId',
                lazy: async () => ({
                  Component: (await import('@/features/grading/routes/GradeSubmissionRoute'))
                    .GradeSubmissionRoute,
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
