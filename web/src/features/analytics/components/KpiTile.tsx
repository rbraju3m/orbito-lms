import { Card, Group, Stack, Text } from '@mantine/core';
import { IconArrowDownRight, IconArrowUpRight, IconMinus } from '@tabler/icons-react';

/**
 * One number, and how it moved.
 *
 * The delta is against the SAME WINDOW immediately before, which the API
 * returns alongside the totals — so the comparison is arithmetic the server
 * already did rather than two requests and some date maths in a component.
 *
 * A rise is not automatically good. `goodDirection` says which way is up for
 * this particular figure, so the colour cannot congratulate somebody on a
 * metric getting worse.
 */
export function KpiTile({
  label,
  value,
  previous,
  format = (n: number) => n.toLocaleString(),
  goodDirection = 'up',
  hint,
}: {
  label: string;
  value: number;
  previous?: number;
  format?: (value: number) => string;
  goodDirection?: 'up' | 'down' | 'neutral';
  hint?: string;
}) {
  /*
   * A change from zero has no percentage — dividing by it gives Infinity, and
   * "+∞%" on a dashboard is how somebody learns not to trust it. Say "new"
   * instead, which is both true and useful.
   */
  const delta =
    previous === undefined
      ? null
      : previous === 0
        ? value === 0
          ? 0
          : null
        : (value - previous) / previous;

  const direction =
    previous === undefined || value === previous ? 'flat' : value > previous ? 'up' : 'down';

  const colour =
    goodDirection === 'neutral' || direction === 'flat'
      ? 'dimmed'
      : (direction === 'up') === (goodDirection === 'up')
        ? 'success.6'
        : 'danger.6';

  const Icon =
    direction === 'up' ? IconArrowUpRight : direction === 'down' ? IconArrowDownRight : IconMinus;

  return (
    <Card withBorder padding="md">
      <Stack gap={4}>
        <Text size="xs" c="dimmed" tt="uppercase" fw={600}>
          {label}
        </Text>

        <Text size="xl" fw={700}>
          {format(value)}
        </Text>

        {previous === undefined ? null : (
          <Group gap={4}>
            <Icon
              size={14}
              color={`var(--mantine-color-${colour === 'dimmed' ? 'dimmed' : colour.replace('.', '-')})`}
            />
            <Text size="xs" c={colour}>
              {delta === null
                ? 'new'
                : `${delta > 0 ? '+' : ''}${(delta * 100).toFixed(delta === 0 ? 0 : 1)}%`}
            </Text>
            <Text size="xs" c="dimmed">
              vs. previous period
            </Text>
          </Group>
        )}

        {hint ? (
          <Text size="xs" c="dimmed">
            {hint}
          </Text>
        ) : null}
      </Stack>
    </Card>
  );
}
