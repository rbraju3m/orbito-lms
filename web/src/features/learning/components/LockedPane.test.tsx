import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { ApiError } from '@/shared/api/errors';
import { renderWithProviders } from '@/shared/test/render';

import { LockedPane } from './LockedPane';

function locked(reason: string, meta: Record<string, unknown> = {}) {
  return new ApiError({
    code: 'content_locked',
    message: 'Server message that the UI must not depend on.',
    status: 423,
    details: [{ code: reason, message: 'x' }],
    meta,
  });
}

/*
 * A 423 is "you could get in", so every one of these asserts that the screen
 * names the way in. The copy keys on the machine `reason`, never on the
 * server's message — which is why every fixture here carries a message the
 * assertions deliberately ignore.
 */
describe('LockedPane', () => {
  it('offers to open the item standing in the way', async () => {
    const onOpenBlocker = vi.fn();

    renderWithProviders(
      <LockedPane
        error={locked('drip_locked', { blocked_by_title: 'Scansion basics' })}
        onOpenBlocker={onOpenBlocker}
      />,
    );

    expect(screen.getByText(/finish the previous lesson first/i)).toBeInTheDocument();

    // The blocking item is named twice on purpose — in the explanation and on
    // the button — so the learner can act without reading the sentence.
    const button = await screen.findByRole('button', { name: /go to .*scansion basics/i });

    await userEvent.click(button);
    expect(onOpenBlocker).toHaveBeenCalledOnce();
  });

  it('gives a date when drip is scheduled rather than sequential', () => {
    renderWithProviders(
      <LockedPane error={locked('drip_locked', { unlocks_at: '2030-03-12T09:00:00Z' })} />,
    );

    expect(screen.getByText(/not available yet/i)).toBeInTheDocument();
    expect(screen.getByText(/unlocks on/i)).toBeInTheDocument();
    // Nothing to click: waiting is the only action.
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });

  it('distinguishes an expired enrolment from never having enrolled', () => {
    const { unmount } = renderWithProviders(<LockedPane error={locked('enrollment_expired')} />);
    expect(screen.getByText(/your access has ended/i)).toBeInTheDocument();
    unmount();

    renderWithProviders(<LockedPane error={locked('not_enrolled')} />);
    expect(screen.getByText(/enrol to open this lesson/i)).toBeInTheDocument();
  });

  it('explains a seat that has not started yet', () => {
    renderWithProviders(
      <LockedPane
        error={locked('enrollment_not_started', { unlocks_at: '2030-01-01T00:00:00Z' })}
      />,
    );

    expect(screen.getByText(/your access has not started/i)).toBeInTheDocument();
  });

  /* An unknown reason must still say something, not render an empty card. */
  it('falls back to the server message for a reason it does not know', () => {
    renderWithProviders(<LockedPane error={locked('something_new')} />);

    expect(screen.getByText(/not available/i)).toBeInTheDocument();
    expect(screen.getByText(/server message that the UI must not depend on/i)).toBeInTheDocument();
  });
});
