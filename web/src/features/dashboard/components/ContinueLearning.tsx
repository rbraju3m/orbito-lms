import { Card, Group, Image, Progress, SimpleGrid, Stack, Text } from '@mantine/core';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';

import { continueLearningQuery } from '@/features/learning/api/queries';
import { EmptyState, ErrorState, LoadingState } from '@/shared/ui';

export function ContinueLearning() {
  const { data, isPending, isError, error, refetch } = useQuery(continueLearningQuery());

  if (isPending) return <LoadingState rows={2} height={90} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  if (data.length === 0) {
    return (
      <EmptyState
        title="Nothing in progress"
        description="Start a course and it will show up here so you can pick it back up."
      />
    );
  }

  return (
    <SimpleGrid cols={{ base: 1, sm: 2 }}>
      {data.map((row) => (
        <Card
          key={row.course.id}
          component={Link}
          to={
            row.resume_item_id
              ? `/learn/${row.course.id}/${row.resume_item_id}`
              : `/learn/${row.course.id}`
          }
          padding="sm"
        >
          <Group wrap="nowrap" align="flex-start" gap="sm">
            {row.course.thumbnail_url ? (
              <Image src={row.course.thumbnail_url} alt="" w={72} h={54} radius="sm" fit="cover" />
            ) : (
              <div
                aria-hidden
                style={{
                  width: 72,
                  height: 54,
                  borderRadius: 'var(--mantine-radius-sm)',
                  background:
                    'linear-gradient(135deg, var(--mantine-color-orbito-5), var(--mantine-color-orbito-8))',
                }}
              />
            )}

            <Stack gap={6} style={{ flex: 1, minWidth: 0 }}>
              <Text fw={600} size="sm" lineClamp={1}>
                {row.course.title}
              </Text>
              <Progress
                value={row.progress.percent}
                size="sm"
                aria-label={`${Math.round(row.progress.percent)} percent complete`}
              />
              <Text size="xs" c="dimmed">
                {row.progress.completed_items} of {row.progress.total_items} lessons
              </Text>
            </Stack>
          </Group>
        </Card>
      ))}
    </SimpleGrid>
  );
}
