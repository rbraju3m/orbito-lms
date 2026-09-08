import {
  ActionIcon,
  Alert,
  Badge,
  Button,
  Card,
  Checkbox,
  Group,
  Modal,
  Stack,
  Text,
  Textarea,
  TextInput,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { IconAlertTriangle, IconPencil, IconSend, IconTrash } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { formatDateTime } from '@/shared/lib/datetime';
import { EmptyState, ErrorState, LoadingState } from '@/shared/ui';

import {
  announcementsQuery,
  useDeleteAnnouncement,
  usePublishAnnouncement,
  useSaveAnnouncement,
} from '../api/queries';
import type { Announcement } from '../api/types';

/**
 * Writing and sending announcements, in the studio.
 *
 * PUBLISHING IS ITS OWN BUTTON, never a checkbox on save. Saving a draft and
 * sending it to a thousand people are different acts, and a half-finished
 * announcement reaching every enrolled learner because somebody hit save is
 * the failure this shape prevents — the same reason the API gives publishing
 * its own endpoint.
 */
export function AnnouncementManager({ courseId }: { courseId: string }) {
  const [editing, setEditing] = useState<Announcement | null>(null);
  const [composerOpen, { open: openComposer, close: closeComposer }] = useDisclosure(false);

  const { data, isPending, isError, error, refetch } = useQuery(announcementsQuery(courseId));

  if (isPending) return <LoadingState rows={2} height={72} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  if (!data.meta.can_manage) {
    return (
      <Alert color="gray" variant="light">
        You can read this course's announcements, but not write them.
      </Alert>
    );
  }

  const compose = (announcement: Announcement | null) => {
    setEditing(announcement);
    openComposer();
  };

  return (
    <Stack gap="md">
      <Group justify="space-between">
        <Text size="sm" c="dimmed">
          Announcements reach everybody currently enrolled.
        </Text>
        <Button size="compact-sm" onClick={() => compose(null)}>
          New announcement
        </Button>
      </Group>

      {data.data.length === 0 ? (
        <EmptyState
          icon={IconSend}
          title="Nothing sent yet"
          description="Write a draft, read it back, then publish it."
          action={{ label: 'New announcement', onClick: () => compose(null) }}
        />
      ) : (
        <Stack gap="sm">
          {data.data.map((announcement) => (
            <AnnouncementRow
              key={announcement.id}
              courseId={courseId}
              announcement={announcement}
              onEdit={() => compose(announcement)}
            />
          ))}
        </Stack>
      )}

      <Modal
        opened={composerOpen}
        onClose={closeComposer}
        title={editing ? 'Edit announcement' : 'New announcement'}
        size="lg"
      >
        <AnnouncementComposer
          courseId={courseId}
          existing={editing}
          onDone={() => {
            setEditing(null);
            closeComposer();
          }}
        />
      </Modal>
    </Stack>
  );
}

function AnnouncementRow({
  courseId,
  announcement,
  onEdit,
}: {
  courseId: string;
  announcement: Announcement;
  onEdit: () => void;
}) {
  const publish = usePublishAnnouncement(courseId);
  const remove = useDeleteAnnouncement(courseId);
  const [confirming, setConfirming] = useState(false);

  return (
    <Card withBorder>
      <Stack gap="xs">
        <Group justify="space-between" wrap="nowrap" align="flex-start">
          <Stack gap={4} style={{ minWidth: 0 }}>
            <Group gap="xs">
              <Text fw={600} lineClamp={1}>
                {announcement.title}
              </Text>
              <Badge
                size="xs"
                variant="light"
                color={announcement.is_published ? 'success' : 'warning'}
              >
                {announcement.is_published ? 'Published' : 'Draft'}
              </Badge>
              {/* What was INTENDED, recorded when the draft was written. */}
              {!announcement.notify ? (
                <Badge size="xs" variant="default">
                  Silent
                </Badge>
              ) : null}
            </Group>

            <Text size="xs" c="dimmed">
              {announcement.published_at
                ? `Sent ${formatDateTime(announcement.published_at)}`
                : 'Not sent'}
            </Text>
          </Stack>

          <Group gap={4} wrap="nowrap">
            <ActionIcon variant="subtle" aria-label="Edit announcement" onClick={onEdit}>
              <IconPencil size={16} />
            </ActionIcon>
            <ActionIcon
              variant="subtle"
              color="danger"
              aria-label="Delete announcement"
              loading={remove.isPending}
              onClick={() => remove.mutate(announcement.id)}
            >
              <IconTrash size={16} />
            </ActionIcon>
          </Group>
        </Group>

        <div className="orbito-prose" dangerouslySetInnerHTML={{ __html: announcement.body }} />

        {announcement.is_published ? (
          <Group>
            <Button
              size="compact-xs"
              variant="default"
              loading={publish.isPending}
              onClick={() => publish.mutate({ id: announcement.id, publish: false })}
            >
              Return to draft
            </Button>
          </Group>
        ) : confirming ? (
          <Alert color="warning" icon={<IconAlertTriangle size={16} />}>
            <Stack gap="xs" align="flex-start">
              <Text size="sm">
                {announcement.notify
                  ? 'This sends an email and an inbox notification to everybody enrolled. It cannot be un-sent.'
                  : 'This publishes to the course without notifying anybody.'}
              </Text>
              <Group gap="xs">
                <Button size="compact-xs" variant="subtle" onClick={() => setConfirming(false)}>
                  Cancel
                </Button>
                <Button
                  size="compact-xs"
                  loading={publish.isPending}
                  onClick={() =>
                    publish.mutate(
                      { id: announcement.id, publish: true },
                      { onSuccess: () => setConfirming(false) },
                    )
                  }
                >
                  Publish now
                </Button>
              </Group>
            </Stack>
          </Alert>
        ) : (
          <Group>
            <Button
              size="compact-xs"
              leftSection={<IconSend size={14} />}
              onClick={() => setConfirming(true)}
            >
              Publish
            </Button>
          </Group>
        )}
      </Stack>
    </Card>
  );
}

function AnnouncementComposer({
  courseId,
  existing,
  onDone,
}: {
  courseId: string;
  existing: Announcement | null;
  onDone: () => void;
}) {
  const save = useSaveAnnouncement(courseId);
  const [title, setTitle] = useState(existing?.title ?? '');
  const [body, setBody] = useState(existing?.body ?? '');
  const [notify, setNotify] = useState(existing?.notify ?? true);

  return (
    <Stack gap="sm">
      <TextInput
        label="Title"
        required
        value={title}
        onChange={(event) => setTitle(event.currentTarget.value)}
      />

      <Textarea
        label="Message"
        required
        minRows={6}
        autosize
        value={body}
        onChange={(event) => setBody(event.currentTarget.value)}
      />

      <Checkbox
        label="Notify enrolled learners when this is published"
        description="Off means it appears in the course quietly, with no email and no inbox entry."
        checked={notify}
        onChange={(event) => setNotify(event.currentTarget.checked)}
      />

      <Group justify="flex-end" gap="xs">
        <Button variant="subtle" onClick={onDone}>
          Cancel
        </Button>
        <Button
          loading={save.isPending}
          disabled={title.trim() === '' || body.trim() === ''}
          onClick={() =>
            save.mutate(
              { ...(existing ? { id: existing.id } : {}), title, body, notify },
              { onSuccess: onDone },
            )
          }
        >
          {/* Saving never sends. Publishing is a separate, deliberate act. */}
          Save draft
        </Button>
      </Group>
    </Stack>
  );
}
