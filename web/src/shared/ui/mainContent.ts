/*
 * Apart from SkipLink.tsx because a file that exports a component should
 * export only components, or Fast Refresh reloads the page instead.
 */

/** The id every shell puts on its main region, and the one place it is spelled. */
export const MAIN_CONTENT_ID = 'main-content';

/**
 * Spread onto a shell's `<main>`. `tabIndex={-1}` lets the skip link focus it
 * without putting the region itself into the Tab order.
 */
export const mainContentProps = { id: MAIN_CONTENT_ID, tabIndex: -1 } as const;
