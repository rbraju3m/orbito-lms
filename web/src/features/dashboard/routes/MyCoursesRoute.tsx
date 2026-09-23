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
import { plural, t } from '@/shared/i18n';
import { formatNumber } from '@/shared/lib/number';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

interface EnrolledRow {
  course: CourseListItem;
  progress: CourseProgress;
  enrollment_status: string | null;
}

// A function: built during render, after the reader's catalogue has arrived.
const filters = () => [
  { value: 'all', label: t('dashboard.my.filter_all', 'All') },
  { value: 'in_progress', label: t('dashboard.my.filter_in_progress', 'In progress') },
  { value: 'completed', label: t('dashboard.my.filter_completed', 'Completed') },
];

/**
 * Enrolment states that close access. `completed` is absent on purpose — a
 * finished course stays open, which is the point of `grantsAccess()` on the
 * server including it.
 */
function closedState(status: string | null): string | undefined {
  switch (status) {
    case 'expired':
      return t('dashboard.my.access_expired', 'Access expired');
    case 'suspended':
      return t('dashboard.my.access_suspended', 'Access suspended');
    case 'cancelled':
      return t('dashboard.my.access_revoked', 'Access revoked');
    default:
      return undefined;
  }
}

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
      <PageHeader
        title={t('dashboard.my.title', 'My learning')}
        description={t('dashboard.my.description', 'Courses you are enrolled in.')}
      />

      <SegmentedControl data={filters()} value={filter} onChange={setFilter} size="sm" mb="lg" />

      {isPending ? <LoadingState rows={3} height={90} /> : null}
      {isError ? <ErrorState error={error} onRetry={() => void refetch()} /> : null}

      {data && data.data.length === 0 ? (
        <EmptyState
          title={
            filter === 'all'
              ? t('dashboard.my.empty_all_title', "You're not enrolled in anything yet")
              : t('dashboard.my.empty_filtered_title', 'Nothing here')
          }
          description={
            filter === 'all'
              ? t(
                  'dashboard.my.empty_all_body',
                  'Browse the catalogue and enrol in something that looks useful.',
                )
              : t('dashboard.my.empty_filtered_body', 'Try a different filter.')
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
                  aria-label={t('dashboard.progress.percent', '{percent} percent complete', {
                    percent: formatNumber(Math.round(row.progress.percent)),
                  })}
                />
                <Group justify="space-between">
                  <Text size="xs" c="dimmed">
                    {plural(
                      'dashboard.progress.lessons',
                      row.progress.total_items,
                      { other: '{done} of {count} lessons' },
                      { done: formatNumber(row.progress.completed_items) },
                    )}
                  </Text>
                  <Text size="xs" c={row.progress.is_complete ? 'success' : 'dimmed'}>
                    {row.progress.is_complete
                      ? t('dashboard.my.completed', 'Completed')
                      : t('dashboard.progress.percent_short', '{percent}%', {
                          percent: formatNumber(Math.round(row.progress.percent)),
                        })}
                  </Text>
                </Group>

                {/*
                  A card the learner can no longer open must say so on the
                  card. Letting them click through to a 423 they cannot act on
                  is the "row that 403s when clicked" failure in a new place.
                */}
                {closedState(row.enrollment_status) ? (
                  <Badge color="gray" variant="light" size="sm">
                    {closedState(row.enrollment_status)}
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
