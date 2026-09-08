import { Button } from '@mantine/core';
import { IconBookmark, IconBookmarkFilled } from '@tabler/icons-react';
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';

import { catalogKeys } from '@/features/catalog/api/keys';

import { useToggleWishlist } from '../api/queries';

/**
 * Save for later.
 *
 * It lives on the course PAGE, not on the catalogue card: a card is one
 * `<Link>`, and a button inside an anchor is invalid markup and hostile to a
 * keyboard — the control would be reachable but would navigate as often as it
 * saved.
 *
 * The toggle is optimistic, which is the one place in this feature that is
 * safe. A wishlist entry owns no money, no grade and no publication, so a
 * rollback puts back exactly what was there.
 */
export function WishlistButton({ courseId, saved }: { courseId: string; saved: boolean }) {
  const queryClient = useQueryClient();
  const [optimistic, setOptimistic] = useState<boolean | null>(null);
  const toggle = useToggleWishlist();

  const isSaved = optimistic ?? saved;

  return (
    <Button
      variant={isSaved ? 'light' : 'default'}
      leftSection={isSaved ? <IconBookmarkFilled size={16} /> : <IconBookmark size={16} />}
      onClick={() => {
        setOptimistic(!isSaved);
        toggle.mutate(
          { courseId, saved: isSaved },
          {
            // The course detail carries `is_wishlisted`, so the truth comes
            // back from the server and the local guess is dropped.
            onSettled: () => {
              setOptimistic(null);
              void queryClient.invalidateQueries({ queryKey: catalogKeys.all });
            },
          },
        );
      }}
    >
      {isSaved ? 'Saved' : 'Save for later'}
    </Button>
  );
}
