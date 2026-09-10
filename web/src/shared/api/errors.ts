import { AxiosError } from 'axios';

import type { ApiErrorBody, ApiErrorDetail, ApiErrorMeta } from './types';

/**
 * Every failure the app handles is one of these.
 *
 * Switch on `code` — a stable machine string — never on `message`, which is
 * localised and may change without notice.
 */
export class ApiError extends Error {
  readonly code: string;
  readonly status: number;
  readonly details: ApiErrorDetail[];
  readonly requestId: string | undefined;
  /** What the caller can do about it, when the API knows. */
  readonly meta: ApiErrorMeta;

  constructor(params: {
    code: string;
    message: string;
    status: number;
    details?: ApiErrorDetail[];
    requestId?: string;
    meta?: ApiErrorMeta;
  }) {
    super(params.message);
    this.name = 'ApiError';
    this.code = params.code;
    this.status = params.status;
    // Guarded: the error path is the worst place to throw a second time if a
    // response ever carries something other than the documented list.
    this.details = Array.isArray(params.details) ? params.details : [];
    this.requestId = params.requestId;
    // Same guard as `details`: the error path is the worst place to throw a
    // second time because a response carried something unexpected.
    this.meta = typeof params.meta === 'object' && params.meta !== null ? params.meta : {};
  }

  /** The first detail's code — the specific reason behind a generic one. */
  get reason(): string | undefined {
    return this.details[0]?.code;
  }

  /**
   * Keys on the CODE, not the status. The API returns 422 for genuine field
   * validation *and* for domain rejections like `invalid_credentials`; only
   * the former carries field-level `details`.
   */
  get isValidation(): boolean {
    return this.code === 'validation_failed';
  }

  get isUnauthenticated(): boolean {
    return this.status === 401;
  }

  get isForbidden(): boolean {
    return this.status === 403;
  }

  get isNotFound(): boolean {
    return this.status === 404;
  }

  /**
   * 423 — the content exists and the caller could legitimately reach it.
   * Not an error screen: a "here is how to get in" screen. `reason`
   * distinguishes drip from an expired enrolment from not being enrolled.
   */
  get isLocked(): boolean {
    return this.status === 423;
  }

  /**
   * 402 — the ACADEMY's subscription lapsed, not anything about this user.
   * Reads still work, so this only ever surfaces on a write.
   *
   * Keyed on the CODE, not the status. `plan_limit_reached` is also a 402 —
   * both are "the academy owes money", which is what the status says — and
   * telling somebody at their course cap that their subscription has lapsed
   * sends them to renew a subscription that is already paid.
   */
  get isSubscriptionLapsed(): boolean {
    return this.code === 'subscription_lapsed';
  }

  /**
   * 402 — the academy's plan has no room for one more of something.
   * `meta` carries which metric, the cap, and what is already used.
   */
  get isPlanLimitReached(): boolean {
    return this.code === 'plan_limit_reached';
  }

  /** Either flavour of "this academy's billing is in the way". */
  get isBillingBlocked(): boolean {
    return this.status === 402;
  }

  /** 4xx is an answer, not a glitch — retrying it is pointless. */
  get isRetryable(): boolean {
    return this.status === 0 || this.status >= 500 || this.status === 429;
  }

  /** Field-keyed messages, ready for react-hook-form's `setError`. */
  fieldErrors(): Record<string, string> {
    const out: Record<string, string> = {};
    for (const detail of this.details) {
      if (detail.field && !(detail.field in out)) {
        out[detail.field] = detail.message;
      }
    }
    return out;
  }
}

function isApiErrorBody(value: unknown): value is ApiErrorBody {
  if (typeof value !== 'object' || value === null || !('error' in value)) return false;
  const error = (value as { error: unknown }).error;
  return (
    typeof error === 'object' &&
    error !== null &&
    typeof (error as { code?: unknown }).code === 'string'
  );
}

/**
 * Turns anything axios throws into an ApiError, so no call site ever inspects
 * an AxiosError directly.
 */
export function normaliseError(error: unknown): ApiError {
  if (error instanceof ApiError) return error;

  if (error instanceof AxiosError) {
    const status = error.response?.status ?? 0;
    const body = error.response?.data;

    if (isApiErrorBody(body)) {
      return new ApiError({
        code: body.error.code,
        message: body.error.message,
        status,
        details: body.error.details ?? [],
        requestId: body.error.request_id,
        meta: body.error.meta,
      });
    }

    if (status === 0) {
      return new ApiError({
        code: 'network_error',
        message: 'Could not reach the server. Check your connection and try again.',
        status: 0,
      });
    }

    return new ApiError({
      code: 'unexpected_response',
      message: error.message || 'The server returned an unexpected response.',
      status,
    });
  }

  return new ApiError({
    code: 'unknown_error',
    message: error instanceof Error ? error.message : 'Something went wrong.',
    status: 0,
  });
}
