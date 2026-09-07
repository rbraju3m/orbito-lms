/**
 * Query key factory. Never inline a queryKey in a component — invalidation
 * targets a prefix from here. See docs/FRONTEND_ARCHITECTURE.md §4.
 */
export const systemKeys = {
  all: ['system'] as const,
  health: () => [...systemKeys.all, 'health'] as const,
};
