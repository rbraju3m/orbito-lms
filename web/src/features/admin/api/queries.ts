import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import type { InstructorProfile, User } from '@/features/auth/api/types';
import { apiGetRaw, apiPost } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

export const adminKeys = {
  all: ['admin'] as const,
  instructors: (status?: string) => [...adminKeys.all, 'instructors', status ?? 'all'] as const,
  users: (filters: Record<string, unknown>) => [...adminKeys.all, 'users', filters] as const,
};

export interface InstructorRow extends InstructorProfile {
  user?: User;
}

export const instructorsQuery = (status?: string) =>
  queryOptions({
    queryKey: adminKeys.instructors(status),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<InstructorRow>>('/admin/instructors', {
        signal,
        params: status ? { status } : undefined,
      }),
  });

export function useReviewInstructor() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, decision, note }: { id: number; decision: string; note?: string }) =>
      apiPost<InstructorProfile>(`/admin/instructors/${id}/review`, { decision, note }),
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: adminKeys.all });
    },
  });
}
