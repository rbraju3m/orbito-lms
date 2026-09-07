import { useState, useSyncExternalStore } from 'react';

/** One shared 1s heartbeat: the number of whole seconds since page load. */
const monotonicSeconds = {
  subscribe(onChange: () => void) {
    const id = window.setInterval(onChange, 1000);

    return () => window.clearInterval(id);
  },
  getSnapshot() {
    return Math.floor(performance.now() / 1000);
  },
  getServerSnapshot() {
    return 0;
  },
};

/**
 * A display-only countdown.
 *
 * It ticks down from the `seconds_remaining` the SERVER reported, and is never
 * the thing that decides whether time ran out — the server re-checks its own
 * deadline on every save and on submit (ADR-06). Changing the device clock
 * moves nothing at all: elapsed time is measured with performance.now(), which
 * is monotonic, and the verdict is the server's either way.
 *
 * The remaining time is derived from that reading rather than accumulated by
 * an interval, so a tab that was suspended for ten minutes shows the right
 * number when it wakes instead of resuming where it stopped.
 */
export function useAttemptCountdown(secondsRemaining: number | null): {
  seconds: number | null;
  expired: boolean;
  label: string | null;
} {
  const tick = useSyncExternalStore(
    monotonicSeconds.subscribe,
    monotonicSeconds.getSnapshot,
    monotonicSeconds.getServerSnapshot,
  );

  // Re-anchored whenever the server reports a new remaining time. Adjusting
  // state during render is React's supported way to follow a prop; an effect
  // would render one frame showing the previous attempt's time.
  const [anchor, setAnchor] = useState({ source: secondsRemaining, at: tick });

  if (anchor.source !== secondsRemaining) {
    setAnchor({ source: secondsRemaining, at: tick });
  }

  const seconds = anchor.source === null ? null : Math.max(0, anchor.source - (tick - anchor.at));

  const label =
    seconds === null
      ? null
      : `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;

  return { seconds, expired: seconds !== null && seconds <= 0, label };
}
