import {
  ActionIcon,
  Badge,
  Button,
  Card,
  Group,
  Pagination,
  SegmentedControl,
  Stack,
  Text,
} from '@mantine/core';
import { IconBell, IconCheck, IconTrash } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate } from 'react-router';

import { formatDateTime } from '@/shared/lib/datetime';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import {
  notificationsQuery,
  useDeleteNotification,
  useMarkAllRead,
  useMarkNotificationRead,
} from '../api/queries';
import type { AppNotification } from '../api/types';

/**
 * The inbox.
 *
 * It is a RECORD, not a feed: every entry stays until somebody deletes it, and
 * the in-app channel cannot be switched off precisely so this list is complete.
 * Silencing email is what the preferences screen is for.
 */
export function NotificationsRoute() {
  const navigate = useNavigate();
  const [page, setPage] = useState(1);
  const [filter, setFilter] = useState<'all' | 'unread'>('all');
  const markAll = useMarkAllRead();

  const { data, isPending, isError, error, refetch } = useQuery(
    notificationsQuery(page, filter === 'unread'),
  );

  if (isPending) return <LoadingState rows={4} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  const unread = data.meta.unread_count;

  return (
    <>
      <PageHeader
        title="Notifications"
        description="Announcements, replies, grades and certificates."
        actions={
          unread > 0 ? (
            <Button
              variant="light"
              leftSection={<IconCheck size={16} />}
              loading={markAll.isPending}
              onClick={() => markAll.mutate()}
            >
              Mark all read
            </Button>
          ) : undefined
        }
      />

      <Stack gap="md">
        <Group justify="space-between">
          <SegmentedControl
            size="xs"
            value={filter}
            onChange={(value) => {
              setFilter(value as 'all' | 'unread');
              // A filter change makes page 3 meaningless.
              setPage(1);
            }}
            data={[
              { value: 'all', label: 'All' },
              { value: 'unread', label: `Unread${unread > 0 ? ` (${unread})` : ''}` },
            ]}
          />
        </Group>

        {data.data.length === 0 ? (
          <EmptyState
            icon={IconBell}
            title={filter === 'unread' ? 'Nothing unread' : 'No notifications yet'}
            description={
              filter === 'unread'
                ? 'You are caught up.'
                : 'When a course announces something, somebody answers your question, or your work is graded, it lands here.'
            }
            {...(filter === 'unread'
              ? { action: { label: 'Show all', onClick: () => setFilter('all') } }
              : {})}
          />
        ) : (
          <Stack gap="xs">
            {data.data.map((notification) => (
              <NotificationRow
                key={notification.id}
                notification={notification}
                onOpen={(path) => void navigate(path)}
              />
            ))}

            {data.meta.last_page > 1 ? (
              <Group justify="center" mt="sm">
                <Pagination value={page} onChange={setPage} total={data.meta.last_page} />
              </Group>
            ) : null}
          </Stack>
        )}
      </Stack>
    </>
  );
}

function NotificationRow({
  notification,
  onOpen,
}: {
  notification: AppNotification;
  onOpen: (path: string) => void;
}) {
  const markRead = useMarkNotificationRead();
  const remove = useDeleteNotification();

  return (
    <Card withBorder padding="sm">
      <Group justify="space-between" wrap="nowrap" align="flex-start" gap="sm">
        <Stack gap={4} style={{ minWidth: 0 }}>
          <Group gap="xs" wrap="wrap">
            <Text fw={notification.is_read ? 500 : 700} lineClamp={1}>
              {notification.title}
            </Text>
            {!notification.is_read ? (
              <Badge size="xs" variant="light">
                New
              </Badge>
            ) : null}
          </Group>

          <Text size="sm" c="dimmed">
            {notification.body}
          </Text>

          <Text size="xs" c="dimmed">
            {formatDateTime(notification.created_at)}
          </Text>
        </Stack>

        <Group gap={4} wrap="nowrap">
          {notification.action_path ? (
            <Button
              size="compact-xs"
              variant="light"
              onClick={() => {
                if (!notification.is_read) markRead.mutate(notification.id);
                onOpen(notification.action_path as string);
              }}
            >
              {notification.action_label ?? 'Open'}
            </Button>
          ) : null}

          {!notification.is_read ? (
            <ActionIcon
              variant="subtle"
              aria-label="Mark read"
              loading={markRead.isPending}
              onClick={() => markRead.mutate(notification.id)}
            >
              <IconCheck size={16} />
            </ActionIcon>
          ) : null}

          <ActionIcon
            variant="subtle"
            color="danger"
            aria-label="Delete notification"
            loading={remove.isPending}
            onClick={() => remove.mutate(notification.id)}
          >
            <IconTrash size={16} />
          </ActionIcon>
        </Group>
      </Group>
    </Card>
  );
}
