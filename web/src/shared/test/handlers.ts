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
    is_platform_operator: false,
    is_platform_owner: false,
    academy: { id: 'academy-1', slug: 'test-academy', name: 'Test Academy' },
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

export function playerFixture(overrides: Record<string, unknown> = {}) {
  const item = (n: number, extra: Record<string, unknown> = {}) => ({
    id: `item-${n}`,
    type: 'lesson',
    type_label: 'Lesson',
    title: `Lesson ${n}`,
    position: n - 1,
    duration_seconds: 300,
    is_preview: false,
    is_completable: true,
    is_self_markable: true,
    status: 'not_started',
    watch_position_seconds: 0,
    // Drip defaults to open; a test that cares overrides these three.
    is_locked: false,
    unlocks_at: null,
    blocked_by: null,
    ...extra,
  });

  return {
    course: {
      id: 'course-uuid',
      slug: 'a-course',
      title: 'Modern Bengali Poetry',
      completion_mode: 'flexible',
      item_count: 3,
      total_duration_seconds: 900,
    },
    access: {
      granted: true,
      reason: 'granted',
      source: 'enrollment',
      is_staff: false,
      expires_at: null,
    },
    progress: {
      completed_items: 0,
      total_items: 3,
      percent: 0,
      is_complete: false,
      started_at: null,
      completed_at: null,
      last_activity_at: null,
      last_item_id: null,
    },
    curriculum: [
      { id: 1, title: 'Foundations', position: 0, items: [item(1), item(2)] },
      { id: 2, title: 'Going deeper', position: 1, items: [item(3)] },
    ],
    ...overrides,
  };
}

export function itemFixture(overrides: Record<string, unknown> = {}) {
  return {
    id: 'item-1',
    type: 'lesson',
    title: 'Lesson 1',
    duration_seconds: 300,
    is_preview: false,
    content: {
      body: '<p>Bengali metre is syllable-counted.</p>',
      format: 'html',
      video_provider: 'none',
      video_url: null,
      video_signed_url: null,
      video_duration_seconds: 0,
    },
    previous_id: null,
    next_id: 'item-2',
    ...overrides,
  };
}

/**
 * A runner payload. Note what it cannot express: is_correct, match_key,
 * accepted answers. That mirrors the server (ADR-06) — a fixture that could
 * carry them would let a test pass on a shape the API never sends.
 */
export function runnerFixture(overrides: Record<string, unknown> = {}) {
  return {
    attempt: {
      id: 'attempt-1',
      attempt_number: 1,
      status: 'in_progress',
      status_label: 'In progress',
      started_at: '2026-09-07T10:00:00+00:00',
      expires_at: '2026-09-07T10:10:00+00:00',
      seconds_remaining: 600,
      submitted_at: null,
      quiz: {
        passing_score_percent: 50,
        feedback_mode: 'default',
        questions_per_page: 1,
        hide_question_numbers: false,
        allow_previous_button: true,
        show_correct_answers: true,
      },
    },
    questions: [
      {
        id: 'q-1',
        ref: 1,
        type: 'single_choice',
        type_label: 'Single choice',
        title: 'Who wrote Gitanjali?',
        body: null,
        points: 1,
        media_url: null,
        options: [
          { id: 11, label: 'Rabindranath Tagore', media_url: null },
          { id: 12, label: 'Kazi Nazrul Islam', media_url: null },
        ],
      },
      {
        id: 'q-2',
        ref: 2,
        type: 'short_answer',
        type_label: 'Short answer',
        title: 'Name one Bengali metre.',
        body: null,
        points: 2,
        media_url: null,
      },
    ],
    answers: {},
    ...overrides,
  };
}

export function attemptResultFixture(overrides: Record<string, unknown> = {}) {
  return {
    attempt: {
      id: 'attempt-1',
      attempt_number: 1,
      status: 'graded',
      status_label: 'Graded',
      started_at: '2026-09-07T10:00:00+00:00',
      expires_at: null,
      seconds_remaining: null,
      submitted_at: '2026-09-07T10:05:00+00:00',
      total_points: 3,
      earned_points: 1,
      percent: 33.33,
      result: 'fail',
      passed: false,
      quiz: {
        passing_score_percent: 50,
        feedback_mode: 'default',
        questions_per_page: 1,
        hide_question_numbers: false,
        allow_previous_button: true,
        show_correct_answers: true,
      },
    },
    review: [
      {
        question_id: 'q-1',
        type: 'single_choice',
        title: 'Who wrote Gitanjali?',
        points_possible: 1,
        points_earned: 1,
        is_correct: true,
        awaiting_review: false,
        your_answer: { option_id: 11 },
        your_answer_label: 'Rabindranath Tagore',
        feedback: null,
        explanation: 'Published in 1910.',
        correct_answer: 'Rabindranath Tagore',
      },
      {
        question_id: 'q-2',
        type: 'short_answer',
        title: 'Name one Bengali metre.',
        points_possible: 2,
        points_earned: 0,
        is_correct: false,
        awaiting_review: false,
        your_answer: { text: 'iambic' },
        your_answer_label: 'iambic',
        feedback: null,
        explanation: null,
        correct_answer: ['payar'],
      },
    ],
    ...overrides,
  };
}

export function quizBuilderFixture(overrides: Record<string, unknown> = {}) {
  return {
    settings: {
      description: null,
      instructions: 'Answer every question.',
      time_limit_seconds: 600,
      time_expiry_policy: 'auto_submit',
      attempts_allowed: 3,
      passing_score_percent: 50,
      grading_policy: 'highest',
      question_order: 'sorted',
      shuffle_answers: false,
      questions_per_attempt: null,
      questions_per_page: 1,
      hide_question_numbers: false,
      feedback_mode: 'default',
      show_correct_answers_after: 'submission',
      negative_marking: false,
      allow_previous_button: true,
    },
    questions: [
      {
        id: 'q-1',
        ref: 1,
        type: 'single_choice',
        type_label: 'Single choice',
        title: 'Who wrote Gitanjali?',
        body: null,
        explanation: null,
        points: 1,
        negative_points: 0,
        settings: null,
        needs_manual_grading: false,
        options: [
          { id: 11, label: 'Rabindranath Tagore', is_correct: true, match_key: null, position: 0 },
          { id: 12, label: 'Kazi Nazrul Islam', is_correct: false, match_key: null, position: 1 },
        ],
      },
    ],
    ...overrides,
  };
}

export function assignmentFixture(overrides: Record<string, unknown> = {}) {
  return {
    instructions: '<p>Write a close reading of one poem.</p>',
    total_points: 50,
    passing_points: 25,
    due_at: null,
    late_policy: 'accept',
    late_policy_label: 'Accept late work in full',
    late_penalty_percent: 0,
    max_attempts: 2,
    allow_text: true,
    allow_files: true,
    max_file_size_kb: 10240,
    max_files: 3,
    allowed_extensions: null,
    attachments: [],
    ...overrides,
  };
}

export function submissionRulesFixture(overrides: Record<string, unknown> = {}) {
  return {
    can_submit: true,
    reason: null,
    attempts_used: 0,
    attempts_allowed: 2,
    attempts_left: 2,
    is_past_due: false,
    will_be_late: false,
    late_penalty_percent: 0,
    ...overrides,
  };
}

export function submissionFixture(overrides: Record<string, unknown> = {}) {
  return {
    id: 'submission-1',
    attempt_number: 1,
    status: 'submitted',
    status_label: 'Awaiting review',
    body: '<p>My close reading.</p>',
    submitted_at: '2026-09-08T09:00:00+00:00',
    is_late: false,
    feedback: null,
    graded_at: null,
    files: [],
    ...overrides,
  };
}

export function assignmentBriefFixture(overrides: Record<string, unknown> = {}) {
  return {
    assignment: assignmentFixture(),
    submissions: [],
    rules: submissionRulesFixture(),
    ...overrides,
  };
}

export function gradingQueueFixture() {
  return [
    {
      kind: 'quiz',
      id: 'attempt-1',
      status: 'awaiting_review',
      awaiting_review: true,
      submitted_at: '2026-09-08T08:00:00+00:00',
      learner: { id: 'user-1', name: 'Anita Roy' },
      item: { id: 'item-9', title: 'Chapter quiz' },
    },
    {
      kind: 'assignment',
      id: 'submission-1',
      status: 'submitted',
      awaiting_review: true,
      submitted_at: '2026-09-08T09:00:00+00:00',
      learner: { id: 'user-2', name: 'Bijoy Das' },
      item: { id: 'item-10', title: 'Close reading' },
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
