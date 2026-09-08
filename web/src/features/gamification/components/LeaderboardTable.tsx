import { Alert, Badge, Card, Group, SegmentedControl, Stack, Table, Text } from '@mantine/core';
import { IconTrophy } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { formatDateTime } from '@/shared/lib/datetime';
import { EmptyState, ErrorState, LoadingState } from '@/shared/ui';

import { leaderboardQuery } from '../api/queries';
import type { LeaderboardPeriod } from '../api/types';

/**
 * A board.
 *
 * WEEKLY BY DEFAULT, and all-time is one option among three rather than the
 * headline. A board nobody new can ever appear on stops being a competition
 * and becomes a list of who joined early — which is discouraging to exactly
 * the people a leaderboard is supposed to pull in.
 */
export function LeaderboardTable({ courseId }: { courseId?: string }) {
  const [period, setPeriod] = useState<LeaderboardPeriod>('weekly');
  const { data, isPending, isError, error, refetch } = useQuery(leaderboardQuery(period, courseId));

  return (
    <Stack gap="md">
      <Group justify="space-between">
        <SegmentedControl
          size="xs"
          value={period}
          onChange={(value) => setPeriod(value as LeaderboardPeriod)}
          data={[
            { value: 'weekly', label: 'This week' },
            { value: 'monthly', label: 'This month' },
            { value: 'all_time', label: 'All time' },
          ]}
          aria-label="Leaderboard period"
        />

        {data?.computed_at ? (
          // A snapshot, so how stale it is belongs on the screen.
          <Text size="xs" c="dimmed">
            Updated {formatDateTime(data.computed_at)}
          </Text>
        ) : null}
      </Group>

      {isPending ? <LoadingState rows={4} height={36} /> : null}
      {isError ? <ErrorState error={error} onRetry={() => void refetch()} /> : null}

      {data && data.entries.length === 0 ? (
        <EmptyState
          icon={IconTrophy}
          title="No board yet"
          description="Boards are rebuilt hourly. Earn some points and check back."
        />
      ) : null}

      {data && data.entries.length > 0 ? (
        <Card withBorder padding={0}>
          <Table verticalSpacing="xs" highlightOnHover>
            <Table.Thead>
              <Table.Tr>
                <Table.Th style={{ width: 60 }}>#</Table.Th>
                <Table.Th>Learner</Table.Th>
                <Table.Th style={{ width: 110 }}>Points</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data.entries.map((entry) => (
                <Table.Tr key={`${entry.rank}-${entry.name}`}>
                  <Table.Td>{entry.rank}</Table.Td>
                  <Table.Td>
                    <Group gap="xs">
                      <Text size="sm" fw={entry.is_you ? 700 : 400}>
                        {entry.name}
                      </Text>
                      {entry.is_you ? (
                        <Badge size="xs" variant="light">
                          You
                        </Badge>
                      ) : null}
                    </Group>
                  </Table.Td>
                  <Table.Td>{entry.points.toLocaleString()}</Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </Card>
      ) : null}

      {/*
       * The only thing on this screen useful to somebody outside the top
       * fifty, and impossible to work out client-side when they are not in
       * the payload at all.
       */}
      {data?.me && !data.entries.some((entry) => entry.is_you) ? (
        <Alert color="gray" variant="light">
          You are {ordinal(data.me.rank)} with {data.me.points.toLocaleString()} points.
        </Alert>
      ) : null}
    </Stack>
  );
}

function ordinal(n: number): string {
  const rest = n % 100;
  if (rest >= 11 && rest <= 13) return `${n}th`;

  return `${n}${['th', 'st', 'nd', 'rd'][n % 10] ?? 'th'}`;
}
