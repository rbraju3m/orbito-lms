import { Badge, Card, Group, Stack, Text } from '@mantine/core';
import { IconSpeakerphone } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';

import { formatDateTime } from '@/shared/lib/datetime';
import { EmptyState, ErrorState, LoadingState } from '@/shared/ui';

import { announcementsQuery } from '../api/queries';

/**
 * What the course team has said, read-only.
 *
 * Deliberately not a discussion with replies switched off: an announcement is
 * one-way, and modelling it as a thread would give it a reply box that has to
 * be disabled everywhere it renders.
 */
export function AnnouncementList({ courseId, limit }: { courseId: string; limit?: number }) {
  const { data, isPending, isError, error, refetch } = useQuery(announcementsQuery(courseId));

  if (isPending) return <LoadingState rows={2} height={72} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  if (data.data.length === 0) {
    return (
      <EmptyState
        icon={IconSpeakerphone}
        title="No announcements"
        description="When the course team posts one, it appears here."
      />
    );
  }

  const rows = limit === undefined ? data.data : data.data.slice(0, limit);

  return (
    <Stack gap="sm">
      {rows.map((announcement) => (
        <Card withBorder key={announcement.id}>
          <Stack gap={6}>
            <Group gap="xs" justify="space-between" wrap="nowrap">
              <Text fw={600}>{announcement.title}</Text>
              {/*
               * Only staff ever see a draft — the API filters the list by what
               * the reader may see, not by a query parameter.
               */}
              {!announcement.is_published ? (
                <Badge size="xs" color="warning" variant="light">
                  Draft
                </Badge>
              ) : null}
            </Group>

            <Text size="xs" c="dimmed">
              {announcement.author.name ?? 'Course team'}
              {announcement.published_at ? ` · ${formatDateTime(announcement.published_at)}` : ''}
            </Text>

            <div className="orbito-prose" dangerouslySetInnerHTML={{ __html: announcement.body }} />
          </Stack>
        </Card>
      ))}
    </Stack>
  );
}
