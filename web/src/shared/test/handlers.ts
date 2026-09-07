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
export const handlers = [
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
