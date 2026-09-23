import type { ComponentPropsWithRef, CSSProperties } from 'react';

/*
 * Off-screen rather than `display: none`: a script that skips invisible
 * inputs still fills one that is merely somewhere else. Out of the tab order
 * and hidden from assistive technology, so no person meets it.
 */
const HIDDEN: CSSProperties = {
  position: 'absolute',
  // Inline-start, so a right-to-left page does not grow a scrollbar for it.
  insetInlineStart: '-10000px',
  top: 'auto',
  width: 1,
  height: 1,
  overflow: 'hidden',
};

/**
 * The `website` field every public form carries and no person fills in. The
 * server discards anything that arrives with it set, answering exactly as it
 * answers a real submission — so nothing on the page may reject it either.
 */
export function HoneypotField(props: ComponentPropsWithRef<'input'>) {
  return (
    <div aria-hidden="true" style={HIDDEN}>
      <label>
        Website
        <input type="text" tabIndex={-1} autoComplete="off" {...props} />
      </label>
    </div>
  );
}
