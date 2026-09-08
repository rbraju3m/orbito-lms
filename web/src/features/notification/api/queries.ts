import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { apiDelete, apiGet, apiGetRaw, apiPost, apiPut } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

import type { AppNotification, NotificationPreferences, PreferenceChange } from './types';

export const notificationKeys = {
  all: ['notifications'] as const,
  list: (page: number, unread: boolean) => [...notificationKeys.all, 'list', page, unread] as const,
  lists: () => [...notificationKeys.all, 'list'] as const,
  unreadCount: () => [...notificationKeys.all, 'unread-count'] as const,
  preferences: () => [...notificationKeys.all, 'preferences'] as const,
};

type InboxPage = Paginated<AppNotification> & {
  meta: Paginated<AppNotification>['meta'] & { unread_count: number };
};

export const notificationsQuery = (page = 1, unread = false) =>
  queryOptions({
    queryKey: notificationKeys.list(page, unread),
    queryFn: ({ signal }) =>
      apiGetRaw<InboxPage>('/notifications', {
        signal,
        params: { page, ...(unread ? { unread: 1 } : {}) },
      }),
    staleTime: 15_000,
  });

/**
 * The badge.
 *
 * Its own endpoint rather than a page of the inbox thrown away for its meta —
 * a bell that polls must not page a list to learn there is nothing new. The
 * refetch interval is the whole reason this feature has no websocket yet: one
 * indexed count a minute is cheaper than a connection per signed-in tab.
 */
export const unreadCountQuery = () =>
  queryOptions({
    queryKey: notificationKeys.unreadCount(),
    queryFn: ({ signal }) =>
      apiGet<{ unread_count: number }>('/notifications/unread-count', { signal }),
    refetchInterval: 60_000,
    staleTime: 30_000,
  });

export function useMarkNotificationRead() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => apiPost<AppNotification>(`/notifications/${id}/read`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: notificationKeys.all });
    },
  });
}

export function useMarkAllRead() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => apiPost<{ unread_count: number }>('/notifications/read-all'),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: notificationKeys.all });
    },
  });
}

export function useDeleteNotification() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => apiDelete(`/notifications/${id}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: notificationKeys.all });
    },
  });
}

export const notificationPreferencesQuery = () =>
  queryOptions({
    queryKey: notificationKeys.preferences(),
    queryFn: ({ signal }) =>
      apiGet<NotificationPreferences>('/notification-preferences', { signal }),
    staleTime: 5 * 60_000,
  });

/**
 * Sends only what moved.
 *
 * Posting the whole matrix back would make every save a race between two open
 * tabs: the older one's copy would silently revert whatever the newer one
 * changed. The response is the whole matrix, so the screen renders from the
 * server's answer rather than from its own guess.
 */
export function useUpdatePreferences() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (preferences: PreferenceChange[]) =>
      apiPut<NotificationPreferences>('/notification-preferences', { preferences }),
    onSuccess: (matrix) => {
      queryClient.setQueryData(notificationKeys.preferences(), matrix);
    },
  });
}
