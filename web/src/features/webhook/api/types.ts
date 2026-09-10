import type { Paginated } from '@/shared/api/types';

/** An academy's outbound webhook endpoint (ADR-12). Never carries its secret. */
export interface WebhookEndpoint {
  id: string;
  url: string;
  description: string | null;
  /** Topic values, e.g. `enrollment.created`. */
  events: string[];
  is_active: boolean;
  /** Deliveries in a row that used up every attempt. */
  consecutive_failures: number;
  disabled_at: string | null;
  disabled_reason: string | null;
  last_delivered_at: string | null;
  created_at: string;
  updated_at: string;
}

/** Only the create and rotate responses have this — shown once, never again. */
export interface WebhookEndpointWithSecret extends WebhookEndpoint {
  secret: string;
}

export interface WebhookTopicOption {
  value: string;
  label: string;
  group: string;
}

export type WebhookEndpointPage = Paginated<WebhookEndpoint> & {
  meta: Paginated<WebhookEndpoint>['meta'] & { topics: WebhookTopicOption[] };
};

export type DeliveryStatus = 'pending' | 'succeeded' | 'failed';

export interface WebhookDelivery {
  id: string;
  /** Shared by every delivery of one event, redeliveries included. */
  event_id: string;
  topic: string;
  status: DeliveryStatus;
  status_label: string;
  attempts: number;
  max_attempts: number;
  next_attempt_at: string | null;
  last_attempt_at: string | null;
  delivered_at: string | null;
  response_status: number | null;
  response_body: string | null;
  error: string | null;
  duration_ms: number | null;
  payload: Record<string, unknown>;
  created_at: string;
}

export interface WebhookEndpointInput {
  url: string;
  description?: string | null;
  events: string[];
}

export interface WebhookEndpointChanges {
  url?: string;
  description?: string | null;
  events?: string[];
  is_active?: boolean;
}
