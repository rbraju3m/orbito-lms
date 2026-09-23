import type { MouseEvent } from 'react';

import { t } from '@/shared/i18n';

import { MAIN_CONTENT_ID } from './mainContent';

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
      {t('shell.skip', 'Skip to content')}
    </a>
  );
}
