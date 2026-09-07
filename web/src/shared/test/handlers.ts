import { http, HttpResponse } from 'msw';

import { API_BASE_URL } from '@/shared/api/client';

export const apiUrl = (path: string) => `${API_BASE_URL}/api/v1${path}`;

/**
 * Default happy-path handlers. Individual tests override with
 * `server.use(...)` for their failure cases.
 *
 * These shapes must match docs/API.md. When the OpenAPI spec lands in Phase 3
 * these are generated from it so they cannot drift.
 */
export const anonymousSession = () =>
  HttpResponse.json(
    {
      error: {
        code: 'unauthenticated',
        message: 'Authentication is required.',
        details: [],
        request_id: 'TEST',
      },
    },
    { status: 401 },
  );

export function sessionFixture(overrides: Record<string, unknown> = {}) {
  return {
    user: {
      id: '018f-uuid',
      name: 'Ada Lovelace',
      email: 'ada@example.com',
      headline: null,
      bio: null,
      timezone: 'UTC',
      locale: 'en',
      status: 'active',
      email_verified: true,
      created_at: '2026-01-01T00:00:00Z',
    },
    roles: ['student'],
    permissions: ['review.create', 'enrollment.view.own'],
    is_instructor: false,
    must_verify_email: false,
    ...overrides,
  };
}

export const handlers = [
  http.get(apiUrl('/auth/me'), () => anonymousSession()),

  http.get(`${API_BASE_URL}/sanctum/csrf-cookie`, () => new HttpResponse(null, { status: 204 })),

  http.get(apiUrl('/health'), () =>
    HttpResponse.json({
      data: {
        status: 'ok',
        app: 'Orbito',
        environment: 'testing',
        version: '0.1.0-phase2',
        time: '2026-09-07T10:00:00Z',
        checks: {
          database: { ok: true },
          cache: { ok: true },
          queue: { ok: true },
        },
      },
    }),
  ),
];
