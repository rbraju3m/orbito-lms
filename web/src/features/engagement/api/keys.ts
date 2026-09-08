/**
 * Query keys for engagement. Every key is built here so an invalidation can
 * target a prefix instead of guessing at the shape a component used.
 */
export const engagementKeys = {
  all: ['engagement'] as const,

  reviews: (courseId: string) => [...engagementKeys.all, 'reviews', courseId] as const,
  reviewPage: (courseId: string, page: number) =>
    [...engagementKeys.reviews(courseId), page] as const,
  reviewQueue: (page: number) => [...engagementKeys.all, 'review-queue', page] as const,
  reviewQueues: () => [...engagementKeys.all, 'review-queue'] as const,

  discussions: (courseId: string) => [...engagementKeys.all, 'discussions', courseId] as const,
  discussionList: (courseId: string, itemId?: string) =>
    [...engagementKeys.discussions(courseId), 'list', itemId ?? 'all'] as const,
  discussion: (id: string) => [...engagementKeys.all, 'discussion', id] as const,

  announcements: (courseId: string) => [...engagementKeys.all, 'announcements', courseId] as const,

  wishlist: () => [...engagementKeys.all, 'wishlist'] as const,
  wishlistPage: (page: number) => [...engagementKeys.wishlist(), page] as const,
};
