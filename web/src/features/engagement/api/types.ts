/**
 * Engagement wire contract. Source of truth: docs/API.md.
 */

import type { CourseListItem } from '@/features/catalog/api/types';

export type ReviewStatus = 'pending' | 'published' | 'rejected';

export interface ReviewAuthor {
  name?: string;
  /** So the UI can offer "edit yours" without guessing at identity. */
  is_you: boolean;
}

export interface Review {
  id: string;
  rating: number;
  title: string | null;
  body: string | null;

  /*
   * Present for everyone, deliberately: a learner whose review is sitting in
   * moderation must be able to see that it has not appeared. Discovering it
   * silently vanished is worse than being told it is pending.
   */
  status: ReviewStatus;
  status_label: string;
  is_published: boolean;

  author: ReviewAuthor;

  instructor_reply: string | null;
  replied_at: string | null;

  published_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export type DiscussionType = 'question' | 'comment';
export type DiscussionStatus = 'open' | 'answered' | 'resolved' | 'hidden';

export interface DiscussionReply {
  id: string;
  body: string;
  parent_id?: string | null;
  /** Read off a stored flag: somebody who answered as staff still did. */
  is_instructor_reply: boolean;
  author: ReviewAuthor;
  created_at: string | null;
}

export interface Discussion {
  id: string;
  type: DiscussionType;
  type_label: string;
  title: string;
  body: string;

  status: DiscussionStatus;
  status_label: string;
  is_resolved: boolean;
  is_pinned: boolean;
  /** Only a question can be answered; a comment must not offer it. */
  is_answerable: boolean;

  reply_count: number;
  last_reply_at: string | null;
  /** Only sent when the replies are loaded — the thread view, not the list. */
  accepted_reply_id?: string | null;

  author: ReviewAuthor;
  item?: { id: string; title: string } | null;
  replies?: DiscussionReply[];

  /**
   * What this reader may do with this thread. Sent on the THREAD view only —
   * each key is a policy call on the server, and a list of thirty would be
   * ninety of them. The list says `can_ask` / `can_moderate` once, in meta.
   */
  viewer?: {
    can_reply: boolean;
    can_accept: boolean;
    can_moderate: boolean;
  };

  created_at: string | null;
}

export interface Announcement {
  id: string;
  title: string;
  body: string;
  is_published: boolean;
  published_at: string | null;
  /** What the author INTENDED — whether publishing should notify. */
  notify: boolean;
  author: { name?: string };
  created_at: string | null;
  updated_at: string | null;
}

export interface WishlistItem {
  saved_at: string | null;
  /** The whole card, so a saved course and a browsed one look identical. */
  course?: CourseListItem;
}

/**
 * What the reader may DO, answered by the same rules the write endpoints
 * enforce. Rendered so a page offers a form or an explanation, never a
 * button that 403s.
 */
export interface ReviewListMeta {
  can_review: boolean;
}

export interface DiscussionListMeta {
  can_ask: boolean;
  can_moderate: boolean;
}

export interface AnnouncementListMeta {
  can_manage: boolean;
}
