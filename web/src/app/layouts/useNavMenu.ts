import { useDisclosure, useMediaQuery, useWindowEvent } from '@mantine/hooks';
import { useRef } from 'react';

/**
 * The burger-and-navbar behaviour every AppShell layout shares, in one place
 * because each piece of it was missing from at least one shell:
 *
 * - AppShell hides a collapsed navbar by sliding it off-screen, which the Tab
 *   key does not respect. `navbarInert` takes it out of the tab order and the
 *   accessibility tree whenever it is not on screen — at desktop width for a
 *   shell whose links live in the header, and below `sm` while closed.
 * - Mantine's Burger draws its state and does not announce it.
 * - Escape closes the menu and returns focus to the burger; otherwise the
 *   focused link turns inert under the reader and focus drops to the page.
 *
 * `sm` is 48em — the breakpoint every shell passes to AppShell.
 */
export function useNavMenu({ onDesktop }: { onDesktop: 'navbar' | 'header' }) {
  const [opened, { toggle, close }] = useDisclosure(false);
  const narrow = useMediaQuery('(max-width: 47.99em)') === true;
  const burgerRef = useRef<HTMLButtonElement>(null);

  useWindowEvent('keydown', (event) => {
    if (event.key === 'Escape' && opened) {
      close();
      burgerRef.current?.focus();
    }
  });

  const navbarShown = narrow ? opened : onDesktop === 'navbar';

  return {
    opened,
    close,
    navbarInert: !navbarShown,
    burgerProps: {
      ref: burgerRef,
      opened,
      onClick: toggle,
      'aria-label': 'Menu',
      'aria-expanded': opened,
    },
  };
}
