import { MutationCache, QueryClient } from '@tanstack/react-query';

import { useSubscriptionLapsed } from '@/features/platform/useSubscriptionLapsed';

import { ApiError } from './errors';

/**
 * Query defaults for the whole app. See docs/FRONTEND_ARCHITECTURE.md §4.
 *
 * The retry rule is the important one: a 4xx is the server's answer, not a
 * transient glitch. Retrying a 403 three times just delays the error state.
 */
export function createQueryClient(): QueryClient {
  return new QueryClient({
    /*
     * A lapsed subscription is an academy-wide fact, not this button's
     * problem, so it is caught once here rather than in every mutation.
     * Individual call sites still render their own error — this only adds the
     * banner that explains why nothing will save.
     */
    mutationCache: new MutationCache({
      onError: (error) => {
        if (error instanceof ApiError && error.isSubscriptionLapsed) {
          useSubscriptionLapsed.getState().report(error.message);
        }
      },
      onSuccess: () => {
        useSubscriptionLapsed.getState().clear();
      },
    }),
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        gcTime: 5 * 60_000,
        refetchOnWindowFocus: 'always',
        retry: (failureCount, error) => {
          if (error instanceof ApiError && !error.isRetryable) return false;
          return failureCount < 2;
        },
      },
      mutations: {
        retry: false,
      },
    },
  });
}
