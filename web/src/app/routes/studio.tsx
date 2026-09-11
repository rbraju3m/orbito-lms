import type { RouteObject } from 'react-router';

import { RequirePermission } from '../guards/RequirePermission';

/**
 * The studio — courses, bundles, downloads and grading. Discovered the first
 * time a `/studio` path is visited (router.tsx), so a learner who never opens
 * it never downloads this table.
 */
export const studioRoutes: RouteObject[] = [
  /*
   * Bundles are an academy-level merchandising decision, so they sit behind
   * `bundle.manage` rather than the studio's own guard — one can contain
   * another instructor's courses, and pricing it decides what that
   * instructor earns.
   */
  /*
   * Downloads are the academy's stock, not an instructor's, so the studio
   * side sits behind `download.manage` like bundles do.
   */
  {
    element: <RequirePermission anyOf={['download.manage']} />,
    children: [
      {
        path: 'studio/downloads',
        lazy: async () => ({
          Component: (await import('@/features/download/routes/StudioDownloadsRoute'))
            .StudioDownloadsRoute,
        }),
      },
      {
        path: 'studio/downloads/:id',
        lazy: async () => ({
          Component: (await import('@/features/download/routes/DownloadEditorRoute'))
            .DownloadEditorRoute,
        }),
      },
    ],
  },

  {
    element: <RequirePermission anyOf={['bundle.manage']} />,
    children: [
      {
        path: 'studio/bundles',
        lazy: async () => ({
          Component: (await import('@/features/bundle/routes/StudioBundlesRoute'))
            .StudioBundlesRoute,
        }),
      },
      {
        path: 'studio/bundles/:id',
        lazy: async () => ({
          Component: (await import('@/features/bundle/routes/BundleEditorRoute'))
            .BundleEditorRoute,
        }),
      },
    ],
  },

  {
    element: <RequirePermission anyOf={['course.create', 'course.update.own']} />,
    children: [
      {
        path: 'studio',
        lazy: async () => ({
          Component: (await import('@/features/studio/routes/StudioHomeRoute')).StudioHomeRoute,
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
          Component: (await import('@/features/studio/routes/NewCourseRoute')).NewCourseRoute,
        }),
      },
      {
        path: 'studio/courses/:id',
        lazy: async () => ({
          Component: (await import('@/features/studio/routes/CourseEditorRoute'))
            .CourseEditorRoute,
        }),
      },
      // Grading sits under the course rather than under a kind: one list of
      // work, whether it came from a quiz or an assignment.
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
];
