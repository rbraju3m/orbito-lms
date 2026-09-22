import type { MouseEvent } from 'react';

/** The id every shell puts on its main region, and the one place it is spelled. */
export const MAIN_CONTENT_ID = 'main-content';

/**
 * Spread onto a shell's `<main>`. `tabIndex={-1}` lets the skip link focus it
 * without putting the region itself into the Tab order.
 */
export const mainContentProps = { id: MAIN_CONTENT_ID, tabIndex: -1 } as const;

/**
 * The first thing Tab reaches on every shell: past the header and nav,
 * straight to the page (docs/DESIGN_SYSTEM.md §5). Hidden until focused.
 *
 * Focuses the target itself rather than following the `#` link. A fragment
 * would put `#main-content` into the router's location — a history entry the
 * Back button then steps through — and only moves the caret in browsers that
 * choose to, so the next Tab could still start back in the header.
 */
export function SkipLink() {
  const skip = (event: MouseEvent<HTMLAnchorElement>) => {
    const main = document.getElementById(MAIN_CONTENT_ID);
    if (main === null) return;

    event.preventDefault();
    main.focus();
  };

  return (
    <a href={`#${MAIN_CONTENT_ID}`} className="orbito-skip-link" onClick={skip}>
      Skip to content
    </a>
  );
}
