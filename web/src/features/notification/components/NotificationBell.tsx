import { ActionIcon, Button, Divider, Group, Indicator, Menu, Stack, Text } from '@mantine/core';
import { IconBell, IconCheck } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router';

import { formatDateTime } from '@/shared/lib/datetime';

import {
  notificationsQuery,
  unreadCountQuery,
  useMarkAllRead,
  useMarkNotificationRead,
} from '../api/queries';
import type { AppNotification } from '../api/types';

/**
 * The bell.
 *
 * The badge comes from its own count endpoint, polled — one indexed count a
 * minute, rather than a websocket per signed-in tab. The LIST is only fetched
 * when the menu opens, so a page nobody clicks costs one small integer.
 */
export function NotificationBell() {
  const navigate = useNavigate();
  const count = useQuery(unreadCountQuery());
  const unread = count.data?.unread_count ?? 0;

  return (
    <Menu position="bottom-end" width={360} withinPortal shadow="md">
      <Menu.Target>
        <Indicator
          disabled={unread === 0}
          label={unread > 99 ? '99+' : unread}
          size={16}
          offset={4}
          color="danger"
        >
          <ActionIcon
            variant="subtle"
            aria-label={`Notifications${unread > 0 ? `, ${unread} unread` : ''}`}
          >
            <IconBell size={18} />
          </ActionIcon>
        </Indicator>
      </Menu.Target>

      <Menu.Dropdown>
        <BellContents onOpenAll={() => void navigate('/notifications')} />
      </Menu.Dropdown>
    </Menu>
  );
}

function BellContents({ onOpenAll }: { onOpenAll: () => void }) {
  // Mounted only when the dropdown renders, which is what keeps the list off
  // the first paint of every page.
  const { data, isPending } = useQuery(notificationsQuery(1, false));
  const markAll = useMarkAllRead();

  const rows = data?.data.slice(0, 6) ?? [];
  const unread = data?.meta.unread_count ?? 0;

  return (
    <Stack gap={4}>
      <Group justify="space-between" px="xs" pt={4}>
        <Text size="sm" fw={600}>
          Notifications
        </Text>
        {unread > 0 ? (
          <Button
            size="compact-xs"
            variant="subtle"
            leftSection={<IconCheck size={12} />}
            loading={markAll.isPending}
            onClick={() => markAll.mutate()}
          >
            Mark all read
          </Button>
        ) : null}
      </Group>

      <Divider />

      {isPending ? (
        <Text size="sm" c="dimmed" px="xs" py="sm">
          Loading…
        </Text>
      ) : rows.length === 0 ? (
        <Text size="sm" c="dimmed" px="xs" py="sm">
          Nothing yet. Announcements, replies and grades land here.
        </Text>
      ) : (
        rows.map((notification) => <BellRow key={notification.id} notification={notification} />)
      )}

      <Divider />

      <Menu.Item onClick={onOpenAll}>
        <Text size="sm" ta="center">
          See all
        </Text>
      </Menu.Item>
    </Stack>
  );
}

function BellRow({ notification }: { notification: AppNotification }) {
  const navigate = useNavigate();
  const markRead = useMarkNotificationRead();

  return (
    <Menu.Item
      onClick={() => {
        if (!notification.is_read) markRead.mutate(notification.id);
        // The stored path is relative, so this is an in-app navigation rather
        // than a full page load.
        if (notification.action_path) void navigate(notification.action_path);
      }}
    >
      <Group gap="xs" wrap="nowrap" align="flex-start">
        {/* An unread marker, not a colour change: colour alone is not a signal. */}
        <Text c={notification.is_read ? 'transparent' : 'orbito'} fw={700} lh={1.2} aria-hidden>
          •
        </Text>
        <Stack gap={0} style={{ minWidth: 0 }}>
          <Text size="sm" fw={notification.is_read ? 400 : 600} lineClamp={1}>
            {notification.title}
          </Text>
          <Text size="xs" c="dimmed" lineClamp={2}>
            {notification.body}
          </Text>
          <Text size="xs" c="dimmed">
            {formatDateTime(notification.created_at)}
          </Text>
        </Stack>
      </Group>
    </Menu.Item>
  );
}
