import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { accountKeys, authKeys } from '@/features/auth/api/keys';
import type { InstructorProfile, User } from '@/features/auth/api/types';
import { apiGet, apiPatch, apiPost } from '@/shared/api/client';
import { ApiError } from '@/shared/api/errors';

export const profileQuery = () =>
  queryOptions({
    queryKey: accountKeys.profile(),
    queryFn: ({ signal }) => apiGet<User>('/account/profile', { signal }),
    staleTime: 60_000,
  });

export const instructorApplicationQuery = () =>
  queryOptions<InstructorProfile | null>({
    queryKey: accountKeys.instructorApplication(),
    queryFn: async ({ signal }) => {
      try {
        return await apiGet<InstructorProfile>('/account/instructor-application', { signal });
      } catch (error) {
        // 404 means "never applied", which is a state, not a failure.
        if (error instanceof ApiError && error.isNotFound) return null;
        throw error;
      }
    },
    staleTime: 60_000,
    retry: false,
  });

export interface UpdateProfilePayload {
  name?: string;
  headline?: string | null;
  bio?: string | null;
  timezone?: string;
  locale?: string;
  social_links?: Record<string, string>;
}

export function useUpdateProfile() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: UpdateProfilePayload) => apiPatch<User>('/account/profile', payload),
    onSuccess: (user) => {
      queryClient.setQueryData(accountKeys.profile(), user);
      // The session carries the user's name; it is now stale.
      void queryClient.invalidateQueries({ queryKey: authKeys.session() });
    },
  });
}

export interface ChangePasswordPayload {
  current_password: string;
  password: string;
  password_confirmation: string;
}

export function useChangePassword() {
  return useMutation({
    mutationFn: (payload: ChangePasswordPayload) =>
      apiPost<{ changed: boolean }>('/account/password', payload),
  });
}

export function useApplyAsInstructor() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (message?: string) =>
      apiPost<InstructorProfile>('/account/instructor-application', { message }),
    onSuccess: (profile) => {
      queryClient.setQueryData(accountKeys.instructorApplication(), profile);
      void queryClient.invalidateQueries({ queryKey: authKeys.session() });
    },
  });
}
