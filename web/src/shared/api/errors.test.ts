import { AxiosError, AxiosHeaders } from 'axios';
import { describe, expect, it } from 'vitest';

import { ApiError, normaliseError } from './errors';

function axiosErrorWith(status: number, data: unknown): AxiosError {
  const error = new AxiosError('Request failed');
  error.response = {
    status,
    statusText: '',
    data,
    headers: new AxiosHeaders(),
    config: { headers: new AxiosHeaders() },
  };
  return error;
}

describe('normaliseError', () => {
  it('parses the documented error envelope', () => {
    const error = normaliseError(
      axiosErrorWith(422, {
        error: {
          code: 'validation_failed',
          message: 'The given data was invalid.',
          details: [{ field: 'email', code: 'invalid', message: 'Email is required.' }],
          request_id: 'ORBITO-1',
        },
      }),
    );

    expect(error).toBeInstanceOf(ApiError);
    expect(error.code).toBe('validation_failed');
    expect(error.status).toBe(422);
    expect(error.requestId).toBe('ORBITO-1');
    expect(error.isValidation).toBe(true);
  });

  it('maps details onto field errors for react-hook-form', () => {
    const error = normaliseError(
      axiosErrorWith(422, {
        error: {
          code: 'validation_failed',
          message: 'Invalid.',
          details: [
            { field: 'email', code: 'invalid', message: 'Email is required.' },
            { field: 'email', code: 'invalid', message: 'A second message for the same field.' },
            { field: 'password', code: 'invalid', message: 'Too short.' },
          ],
          request_id: 'ORBITO-2',
        },
      }),
    );

    // First message per field wins; showing three errors under one input is noise.
    expect(error.fieldErrors()).toEqual({
      email: 'Email is required.',
      password: 'Too short.',
    });
  });

  it('treats a network failure as retryable', () => {
    const error = normaliseError(new AxiosError('Network Error'));

    expect(error.code).toBe('network_error');
    expect(error.status).toBe(0);
    expect(error.isRetryable).toBe(true);
  });

  it('never marks a 4xx as retryable, because it is an answer not a glitch', () => {
    for (const status of [400, 401, 403, 404, 409, 422]) {
      expect(normaliseError(axiosErrorWith(status, {})).isRetryable).toBe(false);
    }
  });

  it('marks 429 and 5xx as retryable', () => {
    for (const status of [429, 500, 502, 503]) {
      expect(normaliseError(axiosErrorWith(status, {})).isRetryable).toBe(true);
    }
  });

  it('survives a response that is not our envelope', () => {
    const error = normaliseError(axiosErrorWith(502, '<html>Bad Gateway</html>'));

    expect(error.code).toBe('unexpected_response');
    expect(error.status).toBe(502);
  });

  it('wraps a non-axios throwable', () => {
    expect(normaliseError(new Error('boom')).code).toBe('unknown_error');
    expect(normaliseError('a string').code).toBe('unknown_error');
  });
});
