import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { apiGet, apiPatch } from '@/shared/api/client';

import type { Achievements, GamificationProfile, Leaderboard, LeaderboardPeriod } from './types';

export const gamificationKeys = {
  all: ['gamification'] as const,
  achievements: () => [...gamificationKeys.all, 'achievements'] as const,
  leaderboard: (period: LeaderboardPeriod, courseId?: string) =>
    [...gamificationKeys.all, 'leaderboard', courseId ?? 'global', period] as const,
};

export const achievementsQuery = () =>
  queryOptions({
    queryKey: gamificationKeys.achievements(),
    queryFn: ({ signal }) => apiGet<Achievements>('/achievements', { signal }),
    /*
     * Points land through a queued listener seconds after the thing that
     * earned them, so this is short: a learner who finishes a lesson and opens
     * this page immediately should not be told they have nothing.
     */
    staleTime: 10_000,
  });

export const leaderboardQuery = (period: LeaderboardPeriod, courseId?: string) =>
  queryOptions({
    queryKey: gamificationKeys.leaderboard(period, courseId),
    queryFn: ({ signal }) =>
      apiGet<Leaderboard>(courseId ? `/courses/${courseId}/leaderboard` : '/leaderboard', {
        signal,
        params: { period },
      }),
    // The board is rebuilt hourly. Asking more often gets the same answer.
    staleTime: 5 * 60_000,
  });

/**
 * Opting in or out of the boards.
 *
 * Never optimistic. It is a privacy setting, and a toggle that flips and then
 * silently reverts is the one kind of control somebody has to be able to
 * trust.
 */
export function useUpdateRanking() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (isRanked: boolean) =>
      apiPatch<GamificationProfile>('/achievements/ranking', { is_ranked: isRanked }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: gamificationKeys.all });
    },
  });
}
