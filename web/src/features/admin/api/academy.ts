import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { apiGet, apiPatch } from '@/shared/api/client';

import { adminKeys } from './queries';

export type RegistrationMode = 'open' | 'invite' | 'closed';

export interface RegistrationModeOption {
  value: RegistrationMode;
  label: string;
  /** Invitations are declared server-side and not built. */
  available: boolean;
}

export interface Academy {
  slug: string;
  name: string;
  support_email: string | null;
  registration_mode: RegistrationMode;
  registration_mode_label: string;
  /** Relative — rendered absolute only at the moment of sharing. */
  signup_path: string;
  registration_modes: RegistrationModeOption[];
}

export const academyQuery = () =>
  queryOptions({
    queryKey: [...adminKeys.all, 'academy'] as const,
    queryFn: ({ signal }) => apiGet<Academy>('/admin/academy', { signal }),
  });

export function useUpdateAcademy() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (changes: { registration_mode?: RegistrationMode; support_email?: string | null }) =>
      apiPatch<Academy>('/admin/academy', changes),
    // Never optimistic. Who may join an academy is not a toggle whose wrong
    // answer is harmless for a moment.
    onSuccess: (academy) => {
      queryClient.setQueryData(academyQuery().queryKey, academy);
    },
  });
}
