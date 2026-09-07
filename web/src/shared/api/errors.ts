import { AxiosError } from 'axios';

import type { ApiErrorBody, ApiErrorDetail } from './types';

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

  constructor(params: {
    code: string;
    message: string;
    status: number;
    details?: ApiErrorDetail[];
    requestId?: string;
  }) {
    super(params.message);
    this.name = 'ApiError';
    this.code = params.code;
    this.status = params.status;
    this.details = params.details ?? [];
    this.requestId = params.requestId;
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
