import { Card, Group, Stack, Text, Title } from '@mantine/core';
import { IconCalendar } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { calendarQuery } from '../api/queries';
import type { LiveSession } from '../api/types';
import { SessionCard } from '../components/SessionCard';

/**
 * What is in somebody's diary.
 *
 * Grouped by day rather than rendered as one list: a calendar is read as
 * "what have I got on Thursday?", and a flat list of thirty rows answers a
 * question nobody asked.
 *
 * A month at a time, and not paginated — a page boundary in the middle of a
 * week is meaningless.
 */
export function CalendarRoute() {
  /*
   * Computed ONCE, in a state initialiser. Calling `new Date()` during render
   * would produce a different query key on every render — a refetch loop that
   * only shows up as a busy network tab, and the linter is right to refuse it.
   */
  const [{ from, to }] = useState(() => ({
    from: new Date().toISOString().slice(0, 10),
    to: new Date(Date.now() + 31 * 86_400_000).toISOString().slice(0, 10),
  }));

  const { data, isPending, isError, error, refetch } = useQuery(calendarQuery(from, to));

  if (isPending) return <LoadingState rows={3} height={80} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  if (data.sessions.length === 0) {
    return (
      <>
        <PageHeader title="Calendar" />
        <EmptyState
          icon={IconCalendar}
          title="Nothing scheduled"
          description="Live sessions from your courses and any webinars you registered for appear here."
        />
      </>
    );
  }

  const days = groupByDay(data.sessions);

  return (
    <>
      <PageHeader title="Calendar" description="The next month, in your own time." />

      <Stack gap="lg">
        {days.map(([day, sessions]) => (
          <Stack key={day} gap="sm">
            <Group gap="xs">
              <Title order={5}>{formatDay(day)}</Title>
              <Text size="xs" c="dimmed">
                {sessions.length} {sessions.length === 1 ? 'session' : 'sessions'}
              </Text>
            </Group>

            <Stack gap="xs">
              {sessions.map((session) => (
                <SessionCard key={session.id} session={session} />
              ))}
            </Stack>
          </Stack>
        ))}

        <Card withBorder padding="sm">
          <Text size="xs" c="dimmed">
            Times are shown in your device's timezone. Each session also lists the zone it was
            scheduled in.
          </Text>
        </Card>
      </Stack>
    </>
  );
}

/** Keyed on the LOCAL day, because that is the day the reader is having. */
function groupByDay(sessions: LiveSession[]): [string, LiveSession[]][] {
  const days = new Map<string, LiveSession[]>();

  for (const session of sessions) {
    const day = new Date(session.starts_at).toLocaleDateString('en-CA');
    days.set(day, [...(days.get(day) ?? []), session]);
  }

  return [...days.entries()];
}

function formatDay(day: string): string {
  return new Date(`${day}T12:00:00`).toLocaleDateString(undefined, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
  });
}
