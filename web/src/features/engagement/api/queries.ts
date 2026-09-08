import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { catalogKeys } from '@/features/catalog/api/keys';
import { apiDelete, apiGet, apiGetRaw, apiPatch, apiPost } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

import { engagementKeys } from './keys';
import type {
  Announcement,
  AnnouncementListMeta,
  Discussion,
  DiscussionListMeta,
  Review,
  ReviewListMeta,
  WishlistItem,
} from './types';

/** A paginated list that also carries what the reader may do with it. */
type WithMeta<T, M> = Paginated<T> & { meta: Paginated<T>['meta'] & M };

/* ------------------------------------------------------------------ reviews */

export const reviewsQuery = (courseId: string, page = 1) =>
  queryOptions({
    queryKey: engagementKeys.reviewPage(courseId, page),
    queryFn: ({ signal }) =>
      apiGetRaw<WithMeta<Review, ReviewListMeta>>(`/courses/${courseId}/reviews`, {
        signal,
        params: { page },
      }),
    staleTime: 30_000,
  });

export interface ReviewInput {
  rating: number;
  title?: string | null;
  body?: string | null;
}

/**
 * Writes or REPLACES the caller's review — there is one per learner per
 * course, so this is idempotent and there is no separate update mutation to
 * keep in step with it.
 *
 * Never optimistic. A review moves the course's rating, and rolling that back
 * on the client would show two different averages in one session.
 */
export function useSubmitReview(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: ReviewInput) => apiPost<Review>(`/courses/${courseId}/reviews`, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: engagementKeys.reviews(courseId) });
      // The rating on the course page is a stored column that just moved.
      void queryClient.invalidateQueries({ queryKey: catalogKeys.all });
    },
  });
}

export function useDeleteReview(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => apiDelete(`/reviews/${id}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: engagementKeys.reviews(courseId) });
      void queryClient.invalidateQueries({ queryKey: catalogKeys.all });
    },
  });
}

export function useReplyToReview(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, reply }: { id: string; reply: string | null }) =>
      apiPost<Review>(`/reviews/${id}/reply`, { reply }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: engagementKeys.reviews(courseId) });
    },
  });
}

export const reviewQueueQuery = (page = 1) =>
  queryOptions({
    queryKey: engagementKeys.reviewQueue(page),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<Review>>('/admin/reviews', { signal, params: { page } }),
    staleTime: 10_000,
  });

/** Publishing or rejecting. Never optimistic — it is a moderation decision. */
export function useModerateReview() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, status }: { id: string; status: 'published' | 'rejected' }) =>
      apiPost<Review>(`/admin/reviews/${id}/moderate`, { status }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: engagementKeys.reviewQueues() });
      void queryClient.invalidateQueries({ queryKey: catalogKeys.all });
    },
  });
}

/* ---------------------------------------------------------------------- Q&A */

export const discussionsQuery = (courseId: string, itemId?: string) =>
  queryOptions({
    queryKey: engagementKeys.discussionList(courseId, itemId),
    queryFn: ({ signal }) =>
      apiGetRaw<WithMeta<Discussion, DiscussionListMeta>>(`/courses/${courseId}/discussions`, {
        signal,
        params: itemId ? { item_id: itemId } : undefined,
      }),
    staleTime: 15_000,
  });

export const discussionQuery = (id: string) =>
  queryOptions({
    queryKey: engagementKeys.discussion(id),
    queryFn: ({ signal }) => apiGet<Discussion>(`/discussions/${id}`, { signal }),
    staleTime: 10_000,
  });

export interface AskInput {
  title: string;
  body: string;
  type?: 'question' | 'comment';
  item_id?: string;
}

export function useAskQuestion(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: AskInput) => apiPost<Discussion>(`/courses/${courseId}/discussions`, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: engagementKeys.discussions(courseId) });
    },
  });
}

/**
 * The reply endpoint returns the WHOLE thread, replies included, so the
 * response is written straight into the detail cache rather than triggering a
 * refetch of something the server just handed us.
 */
export function useReplyToDiscussion(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, body, parentId }: { id: string; body: string; parentId?: string }) =>
      apiPost<Discussion>(`/discussions/${id}/replies`, {
        body,
        ...(parentId ? { parent_id: parentId } : {}),
      }),
    onSuccess: (discussion) => {
      queryClient.setQueryData(engagementKeys.discussion(discussion.id), discussion);
      // reply_count and last_reply_at moved, so the list is stale.
      void queryClient.invalidateQueries({ queryKey: engagementKeys.discussions(courseId) });
    },
  });
}

/** Accepting an answer, or un-accepting when `replyId` is absent. */
export function useAcceptAnswer(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, replyId }: { id: string; replyId?: string }) =>
      apiPost<Discussion>(`/discussions/${id}/accept`, replyId ? { reply_id: replyId } : {}),
    onSuccess: (discussion) => {
      void queryClient.invalidateQueries({ queryKey: engagementKeys.discussion(discussion.id) });
      void queryClient.invalidateQueries({ queryKey: engagementKeys.discussions(courseId) });
    },
  });
}

export function useModerateDiscussion(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      id,
      ...body
    }: {
      id: string;
      status?: 'open' | 'answered' | 'resolved' | 'hidden';
      is_pinned?: boolean;
    }) => apiPatch<Discussion>(`/discussions/${id}/moderate`, body),
    onSuccess: (discussion) => {
      void queryClient.invalidateQueries({ queryKey: engagementKeys.discussion(discussion.id) });
      void queryClient.invalidateQueries({ queryKey: engagementKeys.discussions(courseId) });
    },
  });
}

export function useDeleteReply(courseId: string, discussionId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (replyId: string) => apiDelete(`/discussion-replies/${replyId}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: engagementKeys.discussion(discussionId) });
      void queryClient.invalidateQueries({ queryKey: engagementKeys.discussions(courseId) });
    },
  });
}

/* -------------------------------------------------------------- announcements */

export const announcementsQuery = (courseId: string) =>
  queryOptions({
    queryKey: engagementKeys.announcements(courseId),
    queryFn: ({ signal }) =>
      apiGetRaw<WithMeta<Announcement, AnnouncementListMeta>>(
        `/courses/${courseId}/announcements`,
        { signal },
      ),
    staleTime: 30_000,
  });

export interface AnnouncementInput {
  title: string;
  body: string;
  notify?: boolean;
}

export function useSaveAnnouncement(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, ...input }: AnnouncementInput & { id?: string }) =>
      id === undefined
        ? apiPost<Announcement>(`/courses/${courseId}/announcements`, input)
        : apiPatch<Announcement>(`/announcements/${id}`, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: engagementKeys.announcements(courseId) });
    },
  });
}

/**
 * Publishing is its own mutation, not a field on the save.
 *
 * Saving a draft and sending it to a thousand people are different acts and
 * should not be one careless boolean apart — the same reason the API gives it
 * a separate endpoint. Never optimistic: it sends mail, and there is no
 * rollback for that.
 */
export function usePublishAnnouncement(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, publish }: { id: string; publish: boolean }) =>
      publish
        ? apiPost<Announcement>(`/announcements/${id}/publish`)
        : apiDelete<Announcement>(`/announcements/${id}/publish`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: engagementKeys.announcements(courseId) });
    },
  });
}

export function useDeleteAnnouncement(courseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => apiDelete(`/announcements/${id}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: engagementKeys.announcements(courseId) });
    },
  });
}

/* -------------------------------------------------------------------- wishlist */

export const wishlistQuery = (page = 1) =>
  queryOptions({
    queryKey: engagementKeys.wishlistPage(page),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<WishlistItem>>('/wishlist', { signal, params: { page } }),
    staleTime: 30_000,
  });

/**
 * Saving and unsaving. OPTIMISTIC, which is the one place in this feature it
 * is safe: a wishlist toggle owns no money, no grade and no publication, and
 * a rollback puts back exactly what was there.
 */
export function useToggleWishlist() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ courseId, saved }: { courseId: string; saved: boolean }) =>
      saved ? apiDelete(`/wishlist/${courseId}`) : apiPost(`/wishlist/${courseId}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: engagementKeys.wishlist() });
    },
  });
}
