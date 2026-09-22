import { apiGet, apiPost, ensureCsrfCookie } from '@/shared/api/client';

import type { Session } from './types';

export interface RegisterPayload {
  /**
   * WHICH academy the account joins, by slug.
   *
   * Required. Tenancy resolves from the authenticated user and registration
   * has none, so without it the server has no way to know — and the account
   * would belong nowhere. It comes from the `?academy=` on the signup link an
   * academy hands out.
   */
  academy: string;
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  wants_to_teach?: boolean;
}

/**
 * Registration by invitation (docs/INVITATIONS.md). No email: the account
 * takes the invited address, which following the link proved.
 */
export interface AcceptInvitationPayload {
  academy: string;
  token: string;
  name: string;
  password: string;
  password_confirmation: string;
}

/** What an invitation link is for, shown before a password is chosen. */
export interface InvitationPreview {
  email: string;
  role: 'student' | 'instructor';
  role_label: string;
  academy_name: string;
  expires_at: string;
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

export async function acceptInvitation(payload: AcceptInvitationPayload): Promise<Session> {
  await ensureCsrfCookie();
  return apiPost<Session>('/auth/invitations/accept', payload);
}

/**
 * A POST although it only reads: the token is a credential, and a credential
 * never rides in a URL the API accepts — proxies and access logs keep those.
 */
export async function fetchInvitationPreview(
  academy: string,
  token: string,
  signal?: AbortSignal,
): Promise<InvitationPreview> {
  await ensureCsrfCookie();
  return apiPost<InvitationPreview>(
    `/public/${encodeURIComponent(academy)}/invitations/show`,
    { token },
    { signal },
  );
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
