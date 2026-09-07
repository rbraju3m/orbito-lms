import axios, { type AxiosInstance, type AxiosRequestConfig } from 'axios';

import { normaliseError } from './errors';
import type { Envelope } from './types';

export const API_BASE_URL = import.meta.env['VITE_API_URL'] ?? 'http://localhost:8000';

/**
 * The one HTTP client. Features never call axios directly.
 *
 * `withCredentials` is on because the first-party SPA authenticates with a
 * Sanctum cookie; the XSRF header pair is what makes that safe.
 */
export const http: AxiosInstance = axios.create({
  baseURL: `${API_BASE_URL}/api/v1`,
  withCredentials: true,
  headers: { Accept: 'application/json' },
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  timeout: 30_000,
});

/** Every response failure becomes an ApiError before it reaches a caller. */
http.interceptors.response.use(
  (response) => response,
  (error: unknown) => Promise.reject(normaliseError(error)),
);

/**
 * Sanctum's cookie flow needs a CSRF cookie before the first mutating request.
 * Called by the auth feature before the first login or register.
 */
export async function ensureCsrfCookie(): Promise<void> {
  await axios.get(`${API_BASE_URL}/sanctum/csrf-cookie`, { withCredentials: true });
}

/** Unwraps `{ data: T }` so callers work with T, not the envelope. */
export async function apiGet<T>(url: string, config?: AxiosRequestConfig): Promise<T> {
  const { data } = await http.get<Envelope<T>>(url, config);
  return data.data;
}

export async function apiPost<T>(
  url: string,
  body?: unknown,
  config?: AxiosRequestConfig,
): Promise<T> {
  const { data } = await http.post<Envelope<T>>(url, body, config);
  return data.data;
}

export async function apiPatch<T>(
  url: string,
  body?: unknown,
  config?: AxiosRequestConfig,
): Promise<T> {
  const { data } = await http.patch<Envelope<T>>(url, body, config);
  return data.data;
}

/** For endpoints that replace a whole collection rather than patch one. */
export async function apiPut<T>(
  url: string,
  body?: unknown,
  config?: AxiosRequestConfig,
): Promise<T> {
  const { data } = await http.put<Envelope<T>>(url, body, config);
  return data.data;
}

export async function apiDelete<T = void>(url: string, config?: AxiosRequestConfig): Promise<T> {
  const { data } = await http.delete<Envelope<T>>(url, config);
  return data?.data;
}

/**
 * Paginated responses keep their envelope — callers need `meta` and `links`.
 */
export async function apiGetRaw<T>(url: string, config?: AxiosRequestConfig): Promise<T> {
  const { data } = await http.get<T>(url, config);
  return data;
}
