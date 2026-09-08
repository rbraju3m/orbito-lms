import { Badge, Box, Group, Stack, Table, Text, Tooltip } from '@mantine/core';
import { IconChartFunnel } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';

import { formatDateTime } from '@/shared/lib/datetime';
import { EmptyState, ErrorState, LoadingState } from '@/shared/ui';

import { courseFunnelQuery } from '../api/queries';
import type { FunnelItem } from '../api/types';

/**
 * Where a course loses people.
 *
 * IN CURRICULUM ORDER, never sorted by severity. "They drop out after the
 * third video" is the insight, and a list ordered worst-first destroys exactly
 * the adjacency that makes it visible — so the shading carries the severity
 * and the order carries the shape of the course.
 *
 * It is a TABLE, not a picture. Colour alone is not information: every cell
 * with a shade also prints its number, and the row reads correctly to a screen
 * reader with no colour at all.
 */
export function FunnelHeatmap({ courseId }: { courseId: string }) {
  const { data, isPending, isError, error, refetch } = useQuery(courseFunnelQuery(courseId));

  if (isPending) return <LoadingState rows={4} height={40} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  if (data.items.length === 0) {
    return (
      <EmptyState
        icon={IconChartFunnel}
        title="No one has reached these lessons yet"
        description="The heatmap appears once learners start working through the course."
      />
    );
  }

  const worst = Math.max(...data.items.map((item) => item.drop_off_rate));

  return (
    <Stack gap="sm">
      <Group justify="space-between">
        <Text size="sm" c="dimmed">
          Of everybody who reached each lesson, how many got past it.
        </Text>
        {/* A funnel is a snapshot, so how stale it is belongs on the screen. */}
        <Text size="xs" c="dimmed">
          Computed {formatDateTime(data.items[0]?.computed_at)}
        </Text>
      </Group>

      <Table.ScrollContainer minWidth={560}>
        <Table verticalSpacing="xs" highlightOnHover>
          <Table.Thead>
            <Table.Tr>
              <Table.Th>Lesson</Table.Th>
              <Table.Th style={{ width: 90 }}>Reached</Table.Th>
              <Table.Th style={{ width: 90 }}>Finished</Table.Th>
              <Table.Th style={{ width: 160 }}>Drop-off</Table.Th>
              <Table.Th style={{ width: 110 }}>Avg. watched</Table.Th>
            </Table.Tr>
          </Table.Thead>
          <Table.Tbody>
            {data.items.map((item) => (
              <FunnelRow
                key={item.item_id}
                item={item}
                isWorst={item.drop_off_rate === worst && worst > 0}
              />
            ))}
          </Table.Tbody>
        </Table>
      </Table.ScrollContainer>
    </Stack>
  );
}

function FunnelRow({ item, isWorst }: { item: FunnelItem; isWorst: boolean }) {
  const percent = Math.round(item.drop_off_rate * 100);

  return (
    <Table.Tr>
      <Table.Td>
        <Stack gap={0}>
          <Group gap="xs">
            <Text size="sm">{item.title}</Text>
            {isWorst ? (
              <Badge size="xs" color="danger" variant="light">
                Biggest drop
              </Badge>
            ) : null}
          </Group>
          {item.section_title ? (
            <Text size="xs" c="dimmed">
              {item.section_title}
            </Text>
          ) : null}
        </Stack>
      </Table.Td>

      <Table.Td>{item.started}</Table.Td>
      <Table.Td>{item.completed}</Table.Td>

      <Table.Td>
        <Group gap="xs" wrap="nowrap">
          <Box
            style={{
              flex: 1,
              height: 8,
              borderRadius: 4,
              background: 'var(--mantine-color-default-border)',
              overflow: 'hidden',
            }}
          >
            <Box
              style={{
                width: `${percent}%`,
                height: '100%',
                // Shading carries severity; the number beside it carries the
                // fact, because colour alone is not information.
                background:
                  percent >= 50
                    ? 'var(--mantine-color-danger-6)'
                    : percent >= 25
                      ? 'var(--mantine-color-warning-6)'
                      : 'var(--mantine-color-success-6)',
              }}
            />
          </Box>
          <Text size="sm" style={{ minWidth: 38, textAlign: 'right' }}>
            {percent}%
          </Text>
        </Group>
      </Table.Td>

      <Table.Td>
        {item.avg_seconds === null ? (
          <Tooltip
            label="Nothing to measure — nobody watched, or this lesson has no video"
            withArrow
          >
            <Text size="sm" c="dimmed">
              —
            </Text>
          </Tooltip>
        ) : (
          <Text size="sm">
            {Math.floor(item.avg_seconds / 60)}m {item.avg_seconds % 60}s
          </Text>
        )}
      </Table.Td>
    </Table.Tr>
  );
}
