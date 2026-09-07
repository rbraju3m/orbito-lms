import { apiGet, apiPost, ensureCsrfCookie } from '@/shared/api/client';

import type { Session } from './types';

export interface RegisterPayload {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  wants_to_teach?: boolean;
}

export interface LoginPayload {
  email: string;
  password: string;
  remember?: boolean;
}

export function fetchSession(signal?: AbortSignal): Promise<Session> {
  return apiGet<Session>('/auth/me', { signal });
}

/**
 * Sanctum's cookie flow needs the CSRF cookie before any mutating request.
 * Doing it here means no call site has to remember.
 */
export async function register(payload: RegisterPayload): Promise<Session> {
  await ensureCsrfCookie();
  return apiPost<Session>('/auth/register', payload);
}

export async function login(payload: LoginPayload): Promise<Session> {
  await ensureCsrfCookie();
  return apiPost<Session>('/auth/login', payload);
}

export async function logout(): Promise<void> {
  await apiPost<void>('/auth/logout');
}

export async function forgotPassword(email: string): Promise<{ message: string }> {
  await ensureCsrfCookie();
  return apiPost<{ message: string }>('/auth/forgot-password', { email });
}

export interface ResetPasswordPayload {
  token: string;
  email: string;
  password: string;
  password_confirmation: string;
}

export async function resetPassword(payload: ResetPasswordPayload): Promise<{ reset: boolean }> {
  await ensureCsrfCookie();
  return apiPost<{ reset: boolean }>('/auth/reset-password', payload);
}

/** `search` is the whole signed query string from the emailed link. */
export async function verifyEmail(search: string): Promise<{ verified: boolean }> {
  await ensureCsrfCookie();
  const qs = search.startsWith('?') ? search.slice(1) : search;
  return apiPost<{ verified: boolean }>(`/auth/email/verify?${qs}`);
}

export async function resendVerification(): Promise<{ sent: boolean }> {
  return apiPost<{ sent: boolean }>('/auth/email/resend');
}
