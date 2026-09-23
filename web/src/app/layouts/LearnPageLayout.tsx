import { Box } from '@mantine/core';
import { Outlet } from 'react-router';

import { mainContentProps, SkipLink } from '@/shared/ui';

/**
 * The frame for the learner's pages that sit OUTSIDE the player — a quiz
 * attempt, the announcements, one discussion thread.
 *
 * They are deliberately not inside the player (an attempt has a countdown the
 * player's Next button would discard), and without this they had no shell at
 * all: no `<main>`, nothing for the skip link to land on, every word outside a
 * landmark. The page itself still draws the one `h1`.
 */
export function LearnPageLayout() {
  return (
    <>
      <SkipLink />
      <Box component="main" {...mainContentProps}>
        <Outlet />
      </Box>
    </>
  );
}
