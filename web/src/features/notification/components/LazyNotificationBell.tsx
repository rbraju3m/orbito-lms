import { ActionIcon } from '@mantine/core';
import { IconBell } from '@tabler/icons-react';
import { Suspense, lazy } from 'react';

/*
 * The bell, off the first paint.
 *
 * Measured, not assumed: the bell cost 1.74 KB of first-paint JS in Phase 16
 * (not the 3.8 KB §16 recorded when it landed — its Mantine parts and its icon
 * are shared with the account menu now, so they stay either way). Taking it
 * out brought first paint back under the 250 KB budget.
 */
const NotificationBell = lazy(() =>
  import('./NotificationBell').then((module) => ({ default: module.NotificationBell })),
);

/**
 * Same size, same icon, same place — so the header does not shift when the
 * real bell arrives a moment later. Disabled rather than clickable: a menu
 * that opens onto nothing is worse than a button that waits.
 *
 * `IconBell` is already on first paint (the account menu uses it), so the
 * placeholder costs nothing the shell was not already carrying.
 */
function BellPlaceholder() {
  return (
    <ActionIcon variant="subtle" aria-label="Notifications" disabled>
      <IconBell size={18} />
    </ActionIcon>
  );
}

export function LazyNotificationBell() {
  return (
    <Suspense fallback={<BellPlaceholder />}>
      <NotificationBell />
    </Suspense>
  );
}
