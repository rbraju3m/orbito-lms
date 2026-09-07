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

export function courseFixture(overrides: Record<string, unknown> = {}) {
  return {
    id: '018f-course-uuid',
    slug: 'introduction-to-bengali-poetry',
    title: 'Introduction to Bengali Poetry',
    subtitle: 'From Tagore onwards',
    description: 'A long enough description to satisfy the publish checklist comfortably.',
    level: 'all',
    level_label: 'All levels',
    locale: 'en',
    status: 'draft',
    status_label: 'Draft',
    visibility: 'public',
    completion_mode: 'flexible',
    pricing_model: 'free',
    thumbnail: null,
    thumbnail_media_id: null,
    intro_video_media_id: null,
    intro_video_url: null,
    item_count: 0,
    section_count: 0,
    total_duration_seconds: 0,
    enrollment_count: 0,
    rating_avg: 0,
    rating_count: 0,
    published_at: null,
    created_at: '2026-09-01T00:00:00Z',
    updated_at: '2026-09-01T00:00:00Z',
    category: null,
    tags: [],
    detail: { objectives: [], requirements: [], target_audience: [], materials: [] },
    instructors: [],
    ...overrides,
  };
}

export function checklistFixture(allPassed = true) {
  return [
    {
      code: 'title_present',
      field: 'title',
      message: 'Give the course a title.',
      blocking: true,
      passed: true,
    },
    {
      code: 'description_present',
      field: 'description',
      message: 'Write a description of at least 50 characters.',
      blocking: true,
      passed: allPassed,
    },
    {
      code: 'category_present',
      field: 'category_id',
      message: 'Choose a category so the course can be found.',
      blocking: true,
      passed: allPassed,
    },
    {
      code: 'thumbnail_present',
      field: 'thumbnail_media_id',
      message: 'A thumbnail makes the course far more likely to be opened.',
      blocking: false,
      passed: false,
    },
  ];
}

export function curriculumFixture() {
  const item = (ref: number, sectionId: number, overrides: Record<string, unknown> = {}) => ({
    id: `item-${ref}`,
    ref,
    section_id: sectionId,
    position: ref - 1,
    type: 'lesson',
    type_label: 'Lesson',
    title: `Item ${ref}`,
    is_preview: false,
    is_published: true,
    is_completable: true,
    duration_seconds: 300,
    updated_at: null,
    ...overrides,
  });

  return [
    {
      id: 1,
      title: 'Getting started',
      description: null,
      position: 0,
      items: [item(1, 1), item(2, 1)],
    },
    {
      id: 2,
      title: 'Going deeper',
      description: null,
      position: 1,
      items: [item(3, 2)],
    },
  ];
}

export function paginated<T>(rows: T[]) {
  return {
    data: rows,
    meta: { current_page: 1, per_page: 20, total: rows.length, last_page: 1 },
    links: { first: null, prev: null, next: null, last: null },
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
