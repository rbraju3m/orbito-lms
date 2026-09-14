import type { AdminPostListItem } from '../api/postTypes';

/** How a post's state reads in a badge: draft, scheduled or live. */
export function postState(post: Pick<AdminPostListItem, 'status' | 'is_scheduled'>): {
  label: string;
  color: string;
} {
  if (post.status === 'draft') {
    return { label: 'Draft', color: 'gray' };
  }

  return post.is_scheduled
    ? { label: 'Scheduled', color: 'blue' }
    : { label: 'Published', color: 'green' };
}

/**
 * A `datetime-local` value, read in the author's own zone, as the instant the
 * API stores. Empty means "now" — null, so the server picks the moment.
 */
export function toPublishAt(local: string): string | null {
  if (local.trim() === '') {
    return null;
  }

  const at = new Date(local);

  return Number.isNaN(at.getTime()) ? null : at.toISOString();
}

/** Whether a `datetime-local` value is still ahead — the button then says Schedule. */
export function isFuture(local: string, now: Date = new Date()): boolean {
  const at = toPublishAt(local);

  return at !== null && new Date(at) > now;
}
