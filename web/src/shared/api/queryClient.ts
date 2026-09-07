import { QueryClient } from '@tanstack/react-query';

import { ApiError } from './errors';

/**
 * Query defaults for the whole app. See docs/FRONTEND_ARCHITECTURE.md §4.
 *
 * The retry rule is the important one: a 4xx is the server's answer, not a
 * transient glitch. Retrying a 403 three times just delays the error state.
 */
export function createQueryClient(): QueryClient {
  return new QueryClient({
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
