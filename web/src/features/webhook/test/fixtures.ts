import type { WebhookDelivery, WebhookEndpoint, WebhookTopicOption } from '../api/types';

/* Shared by the webhook route tests. Not a test file, so importing it does not re-register tests. */

export const TOPICS: WebhookTopicOption[] = [
  { value: 'enrollment.created', label: 'Enrolled', group: 'Enrolment' },
  { value: 'course.completed', label: 'Course completed', group: 'Progress' },
];

export function endpointFixture(overrides: Partial<WebhookEndpoint> = {}): WebhookEndpoint {
  return {
    id: 'ep-1',
    url: 'https://hooks.example.com/orbito',
    description: 'CRM sync',
    events: ['enrollment.created'],
    is_active: true,
    consecutive_failures: 0,
    disabled_at: null,
    disabled_reason: null,
    last_delivered_at: null,
    created_at: '2026-09-10T09:00:00Z',
    updated_at: '2026-09-10T09:00:00Z',
    ...overrides,
  };
}

export function deliveryFixture(overrides: Partial<WebhookDelivery> = {}): WebhookDelivery {
  return {
    id: 'dl-1',
    event_id: 'evt-1',
    topic: 'enrollment.created',
    status: 'failed',
    status_label: 'Failed',
    attempts: 8,
    max_attempts: 8,
    next_attempt_at: null,
    last_attempt_at: '2026-09-10T09:05:00Z',
    delivered_at: null,
    response_status: 503,
    response_body: 'down for maintenance',
    error: 'The receiver answered 503.',
    duration_ms: 120,
    payload: { id: 'evt-1', type: 'enrollment.created', data: { learner: { name: 'Rahima' } } },
    created_at: '2026-09-10T09:00:00Z',
    ...overrides,
  };
}
