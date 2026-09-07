import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { ApiError } from '@/shared/api/errors';

import { authKeys } from './keys';
import {
  fetchSession,
  forgotPassword,
  login,
  logout,
  register,
  resetPassword,
  verifyEmail,
} from './requests';
import type { Session } from './types';

/**
 * The session query. A 401 is the answer "you are not signed in", not a
 * failure — so it must not retry and must not be treated as an error state.
 */
export const sessionQuery = () =>
  queryOptions<Session | null>({
    queryKey: authKeys.session(),
    queryFn: async ({ signal }) => {
      try {
        return await fetchSession(signal);
      } catch (error) {
        if (error instanceof ApiError && error.isUnauthenticated) return null;
        throw error;
      }
    },
    staleTime: 60_000,
    retry: false,
  });

export function useLogin() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: login,
    onSuccess: (session) => {
      queryClient.setQueryData(authKeys.session(), session);
    },
  });
}

export function useRegister() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: register,
    onSuccess: (session) => {
      queryClient.setQueryData(authKeys.session(), session);
    },
  });
}

export function useLogout() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: logout,
    onSettled: () => {
      // Clear on settle, not on success: if the request failed because the
      // session was already gone, holding stale user data is worse.
      queryClient.setQueryData(authKeys.session(), null);
      queryClient.clear();
    },
  });
}

export function useForgotPassword() {
  return useMutation({ mutationFn: forgotPassword });
}

export function useResetPassword() {
  return useMutation({ mutationFn: resetPassword });
}

export function useVerifyEmail() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: verifyEmail,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: authKeys.session() });
    },
  });
}
