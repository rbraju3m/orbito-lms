import { Stack } from '@mantine/core';
import { IconVideo } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';

import { EmptyState, ErrorState, LoadingState } from '@/shared/ui';

import { courseSessionsQuery } from '../api/queries';
import { SessionCard } from './SessionCard';

/**
 * A course's live sessions, in the player.
 *
 * Upcoming and past together, in schedule order: "when is the next one?" and
 * "where is the recording of the last one?" are the two questions asked here,
 * and splitting them into tabs makes both take a click.
 */
export function CourseSessionsPanel({ courseId }: { courseId: string }) {
  const { data, isPending, isError, error, refetch } = useQuery(courseSessionsQuery(courseId));

  if (isPending) return <LoadingState rows={2} height={72} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  if (data.data.length === 0) {
    return (
      <EmptyState
        icon={IconVideo}
        title="No live sessions"
        description="When the course team schedules one, it appears here and in your calendar."
      />
    );
  }

  return (
    <Stack gap="xs">
      {data.data.map((session) => (
        <SessionCard key={session.id} session={session} />
      ))}
    </Stack>
  );
}
