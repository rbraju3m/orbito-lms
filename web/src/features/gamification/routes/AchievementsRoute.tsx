import { Card, Group, SimpleGrid, Stack, Switch, Text, Title, Tooltip } from '@mantine/core';
import { IconAward, IconFlame, IconTrophy } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';

import { formatDate } from '@/shared/lib/datetime';
import { ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { achievementsQuery, useUpdateRanking } from '../api/queries';
import { BadgeWall } from '../components/BadgeWall';

/**
 * A learner's own points, badges and streak.
 *
 * This is where the badge notification lands — `/achievements` is the
 * `action_path` the server freezes into the payload, so the route is part of
 * that contract rather than a convenience.
 *
 * There is deliberately no page for somebody ELSE's profile. The leaderboard
 * is the only place another person's points appear, and only for people who
 * did not opt out.
 */
export function AchievementsRoute() {
  const { data, isPending, isError, error, refetch } = useQuery(achievementsQuery());
  const ranking = useUpdateRanking();

  if (isPending) return <LoadingState rows={3} height={90} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  const { profile, badges, recent } = data;
  const held = badges.filter((badge) => badge.is_held).length;

  return (
    <>
      <PageHeader title="Achievements" description="What you have earned in this academy." />

      <Stack gap="lg">
        <SimpleGrid cols={{ base: 1, sm: 3 }} spacing="md">
          <StatCard
            icon={<IconTrophy size={22} />}
            label="Points"
            value={profile.points_total.toLocaleString()}
          />
          <StatCard
            icon={<IconFlame size={22} />}
            label="Current streak"
            value={`${profile.current_streak_days} ${profile.current_streak_days === 1 ? 'day' : 'days'}`}
            hint={
              profile.longest_streak_days > profile.current_streak_days
                ? `Best: ${profile.longest_streak_days} days`
                : undefined
            }
          />
          <StatCard
            icon={<IconAward size={22} />}
            label="Badges"
            value={`${held} of ${badges.length}`}
          />
        </SimpleGrid>

        <Card withBorder>
          <Stack gap="sm">
            <Title order={4}>Badges</Title>
            <BadgeWall badges={badges} />
          </Stack>
        </Card>

        <Card withBorder>
          <Stack gap="sm">
            <Title order={4}>Recent points</Title>

            {recent.length === 0 ? (
              <Text size="sm" c="dimmed">
                Points appear here as you work through a course.
              </Text>
            ) : (
              <Stack gap={4}>
                {recent.map((entry) => (
                  <Group key={`${entry.awarded_at}-${entry.reason}`} justify="space-between">
                    <Text size="sm">{entry.reason ?? 'Points'}</Text>
                    <Group gap="md">
                      <Text size="sm" c="dimmed">
                        {formatDate(entry.awarded_at)}
                      </Text>
                      <Text size="sm" fw={600} c={entry.points < 0 ? 'danger' : 'success'}>
                        {entry.points > 0 ? '+' : ''}
                        {entry.points}
                      </Text>
                    </Group>
                  </Group>
                ))}
              </Stack>
            )}
          </Stack>
        </Card>

        <Card withBorder>
          <Group justify="space-between" wrap="nowrap" gap="md">
            <Stack gap={2}>
              <Text fw={600}>Appear on leaderboards</Text>
              {/*
               * Said plainly, because the fear this setting answers is
               * "will turning it off cost me anything?".
               */}
              <Text size="sm" c="dimmed">
                Turning this off hides your name from the boards. You keep every point, badge and
                day of your streak.
              </Text>
            </Stack>

            <Tooltip
              label={profile.is_ranked ? 'Hide me from boards' : 'Show me on boards'}
              withArrow
            >
              <Switch
                checked={profile.is_ranked}
                disabled={ranking.isPending}
                onChange={(event) => ranking.mutate(event.currentTarget.checked)}
                aria-label="Appear on leaderboards"
              />
            </Tooltip>
          </Group>
        </Card>
      </Stack>
    </>
  );
}

function StatCard({
  icon,
  label,
  value,
  hint,
}: {
  icon: React.ReactNode;
  label: string;
  value: string;
  hint?: string;
}) {
  return (
    <Card withBorder padding="md">
      <Group gap="md" wrap="nowrap">
        {icon}
        <Stack gap={0}>
          <Text size="xs" c="dimmed" tt="uppercase" fw={600}>
            {label}
          </Text>
          <Text size="xl" fw={700}>
            {value}
          </Text>
          {hint ? (
            <Text size="xs" c="dimmed">
              {hint}
            </Text>
          ) : null}
        </Stack>
      </Group>
    </Card>
  );
}
