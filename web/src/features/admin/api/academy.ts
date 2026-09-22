import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { publicKeys } from '@/features/publicsite/api/keys';
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
  /** The numeric media id a save speaks in; null for no logo. */
  logo_media_id: number | null;
  /** What the public site's header draws. */
  logo_url: string | null;
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
    mutationFn: (changes: {
      registration_mode?: RegistrationMode;
      support_email?: string | null;
      logo_media_id?: number | null;
    }) => apiPatch<Academy>('/admin/academy', changes),
    // Never optimistic. Who may join an academy is not a toggle whose wrong
    // answer is harmless for a moment.
    onSuccess: (academy) => {
      queryClient.setQueryData(academyQuery().queryKey, academy);
      // An admin who opens their public site next should see the new header.
      void queryClient.invalidateQueries({ queryKey: publicKeys.academy(academy.slug) });
    },
  });
}
