import { Badge, Button, Card, Group, Stack, Text, Tooltip } from '@mantine/core';
import { IconExternalLink, IconVideo } from '@tabler/icons-react';

import { formatDateTime } from '@/shared/lib/datetime';

import { useJoinSession } from '../api/queries';
import type { LiveSession } from '../api/types';

const STATUS_COLOUR: Record<LiveSession['status'], string> = {
  scheduled: 'gray',
  live: 'danger',
  ended: 'gray',
  cancelled: 'gray',
};

/**
 * One session in a list.
 *
 * THE JOIN BUTTON IS THE ONLY WAY TO GET THE LINK, and that is deliberate: the
 * click is what records attendance, because following the link is the only
 * signal every provider has in common. Rendering the URL as an anchor would
 * leave every roster empty.
 *
 * A session with no link yet is a placeholder the author has not finished, and
 * it says so rather than showing a dead button.
 */
export function SessionCard({ session }: { session: LiveSession }) {
  const join = useJoinSession();

  return (
    <Card withBorder>
      <Group justify="space-between" wrap="nowrap" align="flex-start" gap="md">
        <Stack gap={4} style={{ minWidth: 0 }}>
          <Group gap="xs" wrap="wrap">
            <Text fw={600} lineClamp={1}>
              {session.title}
            </Text>
            <Badge size="xs" variant="light" color={STATUS_COLOUR[session.status]}>
              {session.status_label}
            </Badge>
            {session.cohort ? (
              <Badge size="xs" variant="default">
                {session.cohort.name}
              </Badge>
            ) : null}
          </Group>

          <Text size="sm" c="dimmed">
            {formatDateTime(session.starts_at)}
            {/*
             * The zone it was SCHEDULED in, beside the reader's own time.
             * "7pm Dhaka" is what the instructor said; the local rendering is
             * what the learner needs.
             */}
            {session.timezone ? ` · ${session.timezone}` : ''}
          </Text>

          {session.course ? (
            <Text size="xs" c="dimmed">
              {session.course.title}
            </Text>
          ) : null}
        </Stack>

        <Group gap="xs" wrap="nowrap">
          {session.recording_url ? (
            <Button
              component="a"
              href={session.recording_url}
              target="_blank"
              rel="noopener"
              size="compact-sm"
              variant="subtle"
              leftSection={<IconVideo size={14} />}
            >
              Recording
            </Button>
          ) : null}

          {!session.has_link ? (
            <Tooltip label="The host has not added a link yet" withArrow>
              <Text size="xs" c="dimmed" style={{ whiteSpace: 'nowrap' }}>
                No link yet
              </Text>
            </Tooltip>
          ) : session.can_join ? (
            <Button
              size="compact-sm"
              leftSection={<IconExternalLink size={14} />}
              loading={join.isPending && join.variables === session.id}
              onClick={() =>
                join.mutate(session.id, {
                  // Opened the instant it arrives: the mutation IS the
                  // attendance record, so fetching and opening are one gesture.
                  onSuccess: ({ join_url }) => window.open(join_url, '_blank', 'noopener'),
                })
              }
            >
              Join
            </Button>
          ) : (
            <Text size="xs" c="dimmed" style={{ whiteSpace: 'nowrap' }}>
              {session.status === 'ended' ? 'Ended' : 'Opens 15 min before'}
            </Text>
          )}
        </Group>
      </Group>
    </Card>
  );
}
