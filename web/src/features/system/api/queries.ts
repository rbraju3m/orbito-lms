import { queryOptions } from '@tanstack/react-query';

import { apiGet } from '@/shared/api/client';

import { systemKeys } from './keys';
import type { Health } from './types';

/**
 * Shared between the component, router prefetching, and tests — which is the
 * whole point of `queryOptions` over an inline useQuery config.
 *
 * `validateStatus` is a deliberate, narrow exception to the "non-2xx is an
 * error" rule. The health endpoint answers 503 when a dependency is down so
 * load balancers pull the instance out of rotation — but the body still
 * carries *which* dependency failed, and that is precisely what a human
 * looking at a status page needs. Treating it as a generic error would make
 * the page blank at the only moment it matters.
 */
export const healthQuery = () =>
  queryOptions({
    queryKey: systemKeys.health(),
    queryFn: ({ signal }) =>
      apiGet<Health>('/health', {
        signal,
        validateStatus: (status) => status === 200 || status === 503,
      }),
    staleTime: 10_000,
  });
