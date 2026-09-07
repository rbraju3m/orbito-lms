import {
  Badge,
  Card,
  Container,
  Group,
  Progress,
  SegmentedControl,
  SimpleGrid,
  Stack,
  Text,
} from '@mantine/core';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link } from 'react-router';

import type { CourseListItem } from '@/features/catalog/api/types';
import { learnKeys } from '@/features/learning/api/queries';
import type { CourseProgress } from '@/features/learning/api/types';
import { apiGetRaw } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

interface EnrolledRow {
  course: CourseListItem;
  progress: CourseProgress;
  enrollment_status: string | null;
}

const FILTERS = [
  { value: 'all', label: 'All' },
  { value: 'in_progress', label: 'In progress' },
  { value: 'completed', label: 'Completed' },
];

/**
 * Enrolment states that close access. `completed` is absent on purpose — a
 * finished course stays open, which is the point of `grantsAccess()` on the
 * server including it.
 */
const CLOSED_STATES: Record<string, string | undefined> = {
  expired: 'Access expired',
  suspended: 'Access suspended',
  cancelled: 'Access revoked',
};

export function MyCoursesRoute() {
  const [filter, setFilter] = useState('all');

  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: learnKeys.myCourses(filter),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<EnrolledRow>>('/learn/courses', { signal, params: { filter } }),
    staleTime: 30_000,
  });

  return (
    <Container size="lg" py="lg">
      <PageHeader title="My learning" description="Courses you are enrolled in." />

      <SegmentedControl data={FILTERS} value={filter} onChange={setFilter} size="sm" mb="lg" />

      {isPending ? <LoadingState rows={3} height={90} /> : null}
      {isError ? <ErrorState error={error} onRetry={() => void refetch()} /> : null}

      {data && data.data.length === 0 ? (
        <EmptyState
          title={filter === 'all' ? "You're not enrolled in anything yet" : 'Nothing here'}
          description={
            filter === 'all'
              ? 'Browse the catalogue and enrol in something that looks useful.'
              : 'Try a different filter.'
          }
        />
      ) : null}

      {data && data.data.length > 0 ? (
        <SimpleGrid cols={{ base: 1, sm: 2 }}>
          {data.data.map((row) => (
            <Card key={row.course.id} component={Link} to={`/learn/${row.course.id}`}>
              <Stack gap="xs">
                <Text fw={600} lineClamp={1}>
                  {row.course.title}
                </Text>
                <Progress
                  value={row.progress.percent}
                  size="sm"
                  color={row.progress.is_complete ? 'success' : undefined}
                  aria-label={`${Math.round(row.progress.percent)} percent complete`}
                />
                <Group justify="space-between">
                  <Text size="xs" c="dimmed">
                    {row.progress.completed_items} of {row.progress.total_items} lessons
                  </Text>
                  <Text size="xs" c={row.progress.is_complete ? 'success' : 'dimmed'}>
                    {row.progress.is_complete
                      ? 'Completed'
                      : `${Math.round(row.progress.percent)}%`}
                  </Text>
                </Group>

                {/*
                  A card the learner can no longer open must say so on the
                  card. Letting them click through to a 423 they cannot act on
                  is the "row that 403s when clicked" failure in a new place.
                */}
                {CLOSED_STATES[row.enrollment_status ?? ''] ? (
                  <Badge color="gray" variant="light" size="sm">
                    {CLOSED_STATES[row.enrollment_status ?? '']}
                  </Badge>
                ) : null}
              </Stack>
            </Card>
          ))}
        </SimpleGrid>
      ) : null}
    </Container>
  );
}
