import { PageHeader } from '@/shared/ui';

import { LeaderboardTable } from '../components/LeaderboardTable';

export function LeaderboardRoute() {
  return (
    <>
      <PageHeader
        title="Leaderboard"
        description="Points earned across the academy. Rebuilt hourly."
      />
      <LeaderboardTable />
    </>
  );
}
