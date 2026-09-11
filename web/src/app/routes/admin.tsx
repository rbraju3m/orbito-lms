import type { RouteObject } from 'react-router';

import { RequirePermission } from '../guards/RequirePermission';

/**
 * The academy's admin area. Discovered the first time an `/admin` path is
 * visited (router.tsx), so a learner never downloads this table.
 */
export const adminRoutes: RouteObject[] = [
  {
    element: <RequirePermission anyOf={['user.view', 'settings.view', 'instructor.view']} />,
    children: [
      {
        path: 'admin',
        lazy: async () => ({
          Component: (await import('@/features/admin/routes/AdminHomeRoute')).AdminHomeRoute,
        }),
      },
      {
        path: 'admin/instructors',
        lazy: async () => ({
          Component: (await import('@/features/admin/routes/InstructorsRoute')).InstructorsRoute,
        }),
      },
      // The academy administering ITSELF — who may join it. Not the platform
      // registry, which is the operator's and sits under /platform behind a
      // flag rather than a permission.
      {
        element: <RequirePermission anyOf={['settings.view']} />,
        children: [
          {
            path: 'admin/academy',
            lazy: async () => ({
              Component: (await import('@/features/admin/routes/AcademySettingsRoute'))
                .AcademySettingsRoute,
            }),
          },
          /*
           * What the academy has used against its plan. Same permission as its
           * other settings — an academy reading its own meter — and NOT the
           * operator's registry, which shows every academy's and lives under
           * /platform.
           */
          {
            path: 'admin/plan',
            lazy: async () => ({
              Component: (await import('@/features/admin/routes/PlanUsageRoute')).PlanUsageRoute,
            }),
          },
        ],
      },
      /*
       * Connecting the academy's own gateway (ADR-13). Its own permission:
       * staff run orders but move no money, so `order.view.any` must not open
       * this screen.
       */
      {
        element: <RequirePermission anyOf={['certificate.template.manage']} />,
        children: [
          {
            path: 'admin/certificate-templates',
            lazy: async () => ({
              Component: (
                await import('@/features/certification/routes/CertificateTemplatesRoute')
              ).CertificateTemplatesRoute,
            }),
          },
        ],
      },
      {
        element: <RequirePermission anyOf={['analytics.view.platform']} />,
        children: [
          {
            path: 'admin/analytics',
            lazy: async () => ({
              Component: (await import('@/features/analytics/routes/AnalyticsOverviewRoute'))
                .AnalyticsOverviewRoute,
            }),
          },
        ],
      },
      {
        element: <RequirePermission anyOf={['review.moderate']} />,
        children: [
          {
            path: 'admin/reviews',
            lazy: async () => ({
              Component: (await import('@/features/engagement/routes/ReviewModerationRoute'))
                .ReviewModerationRoute,
            }),
          },
        ],
      },
      {
        element: <RequirePermission anyOf={['gateway.manage']} />,
        children: [
          {
            path: 'admin/payment-gateways',
            lazy: async () => ({
              Component: (await import('@/features/commerce/routes/PaymentGatewaysRoute'))
                .PaymentGatewaysRoute,
            }),
          },
        ],
      },
      {
        // Refunds the provider reported that the books could not take in.
        element: <RequirePermission anyOf={['order.refund']} />,
        children: [
          {
            path: 'admin/refund-reports',
            lazy: async () => ({
              Component: (await import('@/features/commerce/routes/RefundReportsRoute'))
                .RefundReportsRoute,
            }),
          },
        ],
      },
      {
        element: <RequirePermission anyOf={['coupon.manage']} />,
        children: [
          {
            path: 'admin/coupons',
            lazy: async () => ({
              Component: (await import('@/features/commerce/routes/CouponsRoute')).CouponsRoute,
            }),
          },
        ],
      },
      {
        element: <RequirePermission anyOf={['webhook.manage']} />,
        children: [
          {
            path: 'admin/webhooks',
            lazy: async () => ({
              Component: (await import('@/features/webhook/routes/WebhooksRoute')).WebhooksRoute,
            }),
          },
          {
            path: 'admin/webhooks/:endpointId',
            lazy: async () => ({
              Component: (await import('@/features/webhook/routes/WebhookEndpointRoute'))
                .WebhookEndpointRoute,
            }),
          },
        ],
      },
    ],
  },
];
